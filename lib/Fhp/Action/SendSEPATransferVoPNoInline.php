<?php

namespace Fhp\Action;

use Fhp\Protocol\BPD;
use Fhp\Protocol\UPD;
use Fhp\Segment\VPP\HIVPPSv1;
use Fhp\Segment\VPP\HKVPPv1;

class SendSEPATransferVoPNoInline extends SendSEPATransferVoP
{
    protected function createRequest(BPD $bpd, ?UPD $upd)
    {
        // Is this the first request (initiating VoP)?
        if ($this->hivpp === null && !$this->vopNeedsConfirmation) {
            $this->vopRequired = true;

            // Create only the VoP request (HKVPP)
            $this->hkvpp = HKVPPv1::createEmpty();
            /** @var HIVPPSv1 $hivpps */
            if ($hivpps = $bpd->getLatestSupportedParameters('HIVPPS')) {
                $supportedFormats = explode(';', $hivpps->parameter->unterstuetztePaymentStatusReportDatenformate);
                $this->hkvpp->unterstuetztePaymentStatusReports->paymentStatusReportDescriptor = $supportedFormats;
            }
            return $this->hkvpp; // Send only HKVPP
        }

        // Second request: send the transfer request (parent implementation)
        return parent::createRequest($bpd, $upd);
    }
}