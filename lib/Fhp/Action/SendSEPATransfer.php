<?php

namespace Fhp\Action;

use Fhp\BaseAction;
use Fhp\Model\SEPAAccount;
use Fhp\Protocol\BPD;
use Fhp\Protocol\Message;
use Fhp\Protocol\UnexpectedResponseException;
use Fhp\Protocol\UPD;
use Fhp\Segment\Common\Btg;
use Fhp\Segment\Common\Kti;
use Fhp\Segment\HIRMS\Rueckmeldungscode;
use Fhp\Segment\SPA\HISPAS;
use Fhp\Syntax\Bin;
use Fhp\UnsupportedException;

/**
 * Initiates an outgoing wire transfer in SEPA format (PAIN XML).
 */
class SendSEPATransfer extends BaseAction
{
    /** @var SEPAAccount */
    private $account;
    /** @var string */
    private $painMessage;
    /** @var string */
    private $xmlSchema;

    /**
     * @param SEPAAccount $account The account from which the transfer will be sent.
     * @param string $painMessage An XML-formatted ISO 20022 message. You may want to use github.com/nemiah/phpSepaXml
     *     to create this.
     * @return SendSEPATransfer A new action for executing this the given PAIN message.
     */
    public static function create(SEPAAccount $account, string $painMessage): static
    {
        if (preg_match('/xmlns="(.*?)"/', $painMessage, $match) === false) {
            throw new \InvalidArgumentException('xmlns not found in the PAIN message');
        }
        $result = new static();
        $result->account = $account;
        $result->painMessage = $painMessage;
        $result->xmlSchema = $match[1];
        return $result;
    }

    protected function createRequest(BPD $bpd, ?UPD $upd)
    {
        //ANALYSE XML FOR RECEIPTS AND PAYMENT DATE
        $xmlAsObject = simplexml_load_string($this->painMessage, "SimpleXMLElement", LIBXML_NOCDATA);
        $numberOfTransactions = $xmlAsObject->CstmrCdtTrfInitn->GrpHdr->NbOfTxs;
        $CtrlSum = round((float)$xmlAsObject->CstmrCdtTrfInitn->GrpHdr->CtrlSum, 2);
        $hasReqdExDates = false;
        $batchBooking = false;
        foreach ($xmlAsObject->CstmrCdtTrfInitn?->PmtInf as $pmtInfo) {
            $CtrlSum += (float)$pmtInfo->CtrlSum;
            // Checks for both, <ReqdExctnDt>1999-01-01</ReqdExctnDt> and <ReqdExctnDt><Dt>1999-01-01</Dt></ReqdExctnDt>
            if (isset($pmtInfo->ReqdExctnDt) && ($pmtInfo->ReqdExctnDt->Dt ?? $pmtInfo->ReqdExctnDt) != '1999-01-01') $hasReqdExDates = true;
            if (isset($pmtInfo->BtchBookg)) $batchBooking = (string)$pmtInfo->BtchBookg == 'true';
        }

        //CHECK IF $hasReqdExDates and set other to tomorrow
        if ($hasReqdExDates) {
            foreach ($xmlAsObject->CstmrCdtTrfInitn?->PmtInf as $pmtInfo) {
                if (isset($pmtInfo->ReqdExctnDt) && $pmtInfo->ReqdExctnDt == '1999-01-01') {
                    throw new UnsupportedException('Terminierte SEPA-Sammelüberweisung (Segment HKCME / Kennung HICMES) requires all entries to be in future');
                }
            }
            if (isset($pmtInfo->BtchBookg)) $batchBooking = (string)$pmtInfo->BtchBookg == 'true';
        }

        //CHECK IF $hasReqdExDates and set other to tomorrow
        if ($hasReqdExDates) {
            foreach ($xmlAsObject->CstmrCdtTrfInitn?->PmtInf as $pmtInfo) {
                if (isset($pmtInfo->ReqdExctnDt) && $pmtInfo->ReqdExctnDt == '1999-01-01') {
                    throw new UnsupportedException('Terminierte SEPA-Sammelüberweisung (Segment HKCME / Kennung HICMES) requires all entries to be in future');
                }
            }
        }


        //NOW READ OUT, WICH SEGMENT SHOULD BE USED:
        if ($numberOfTransactions > 1 && $hasReqdExDates) {

            // Terminierte SEPA-Sammelüberweisung (Segment HKCME / Kennung HICMES)
            $segmentID = 'HICMES';
            $segment = \Fhp\Segment\CME\HKCMEv1::createEmpty();
            $segment->summenfeld = Btg::create($CtrlSum);
            $segment->einzelbuchungGewuenscht = $batchBooking;
        } elseif ($numberOfTransactions == 1 && $hasReqdExDates) {

            // Terminierte SEPA-Überweisung (Segment HKCSE / Kennung HICSES)
            $segmentID = 'HICSES';
            $segment = \Fhp\Segment\CSE\HKCSEv1::createEmpty();
        } elseif ($numberOfTransactions > 1 && !$hasReqdExDates) {

            // SEPA-Sammelüberweisungen (Segment HKCCM / Kennung HICCMS)
            $segmentID = 'HICCMS';
            $segment = \Fhp\Segment\CCM\HKCCMv1::createEmpty();
            $segment->summenfeld = Btg::create($CtrlSum);
            $segment->einzelbuchungGewuenscht = $batchBooking;
        } else {

            //SEPA Einzelüberweisung (Segment HKCCS / Kennung HICCSS).
            $segmentID = 'HICCSS';
            $segment = \Fhp\Segment\CCS\HKCCSv1::createEmpty();
        }

        if (!$bpd->supportsParameters($segmentID, 1)) {
            throw new UnsupportedException('The bank does not support ' . $segmentID . 'v1');
        }

        /** @var HISPAS $hispas */
        $hispas = $bpd->requireLatestSupportedParameters('HISPAS');
        $supportedSchemas = $hispas->getParameter()->getUnterstuetzteSEPADatenformate();

        // Sometimes the Bank reports supported schemas with a "_GBIC_X" postfix.
        // GIBC_X stands for German Banking Industry Committee and a version counter.
        $xmlSchema = $this->xmlSchema;
        $matchingSchemas = array_filter($supportedSchemas, function($value) use ($xmlSchema) {
            // For example urn:iso:std:iso:20022:tech:xsd:pain.001.001.09 from the xml matches
            // urn:iso:std:iso:20022:tech:xsd:pain.001.001.09_GBIC_4
            return str_starts_with($value, $xmlSchema);
        });

        if (count($matchingSchemas) === 0) {
            throw new UnsupportedException("The bank does not support the XML schema $this->xmlSchema, but only "
                . implode(', ', $supportedSchemas));
        }

        $segment->kontoverbindungInternational = Kti::fromAccount($this->account);
        $segment->sepaDescriptor = $this->xmlSchema;
        $segment->sepaPainMessage = new Bin($this->painMessage);

        return $segment;
    }

    public function processResponse(Message $response)
    {
        parent::processResponse($response);
        if ($response->findRueckmeldung(Rueckmeldungscode::ENTGEGENGENOMMEN) === null && $response->findRueckmeldung(Rueckmeldungscode::AUSGEFUEHRT) === null) {
            throw new UnexpectedResponseException('Bank did not confirm SEPATransfer execution');
        }
    }
}
