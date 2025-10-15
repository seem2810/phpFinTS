<?php

namespace Fhp\Segment\VPP;

use Fhp\Protocol\BPD;
use Fhp\Protocol\Message;
use Fhp\Segment\HIRMS\Rueckmeldungscode;

/** Creates request segments and interprets response segments and Ruckmeldungscodes for anything related to VoP. */
class VopHelper
{
    /**
     * @param BPD $bpd The BPD.
     * @return HKVPPv1 A segment to prompt the server to do Verification of Payee.
     */
    public static function createHKVPPForInitialRequest(BPD $bpd): HKVPPv1
    {
        // For now just pretend we support all formats.
        /** @var HIVPPSv1 $hivpps */
        $hivpps = $bpd->getLatestSupportedParameters('HIVPPS');
        $supportedFormats = explode(';', $hivpps->parameter->unterstuetztePaymentStatusReportDatenformate);

        $hkvpp = HKVPPv1::createEmpty();
        $hkvpp->unterstuetztePaymentStatusReports->paymentStatusReportDescriptor = $supportedFormats;
        return $hkvpp;
    }

    /**
     * @param BPD $bpd The BPD.
     * @param VopPollingToken $pollingToken The polling token we got on the immediately preceding request.
     * @return HKVPPv1 A segment to poll the server for the completion of Verification of Payee.
     */
    public static function createHKVPPForPollingRequest(BPD $bpd, VopPollingToken $pollingToken): HKVPPv1
    {
        $hkvpp = static::createHKVPPForInitialRequest($bpd);
        $hkvpp->aufsetzpunkt = $pollingToken->getAufsetzpunkt();
        $hkvpp->pollingId = $pollingToken->getPollingId();
        return $hkvpp;
    }

    /**
     * @param Message $response The response we just received from the server.
     * @param int $hkvppSegmentNumber The number of the HKVPP segment in the request we had sent.
     * @return ?VopPollingToken If the response indicates that the Verification of Payee is still ongoing, such that the
     *     client should keep polling the server to (actively) wait until the result is available, this function returns
     *     a corresponding polling token. If no polling is required, it returns null.
     */
    public static function checkPollingRequired(Message $response, int $hkvppSegmentNumber): ?VopPollingToken
    {
        $aufsetzpunkt = $response->findRueckmeldung(Rueckmeldungscode::AUFSETZPUNKT, $hkvppSegmentNumber);
        if ($aufsetzpunkt === null) {
            return null;
        }
        /** @var HIVPPv1 $hivpp */
        $hivpp = $response->findSegment('HIVPP');
        return new VopPollingToken(
            $aufsetzpunkt->rueckmeldungsparameter[0],
            $hivpp?->pollingId,
            $hivpp?->wartezeitVorNaechsterAbfrage,
        );
    }
}
