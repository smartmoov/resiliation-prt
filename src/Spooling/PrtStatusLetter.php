<?php
/**
 * Recherche les informations sur le statut d'un spool routé par PRT
 * @see PRT Documentation
 *
 * Created by PhpStorm.
 * User: stephanepau
 * Date: 09/09/2017
 * Time: 12:00
 * PHP version 7.1
 *
 * @category  Spooling
 * @package   Resiliation
 * @author    Stephane <smartmoov.solutions@gmail.com>
 * @copyright 2017 by MSP This file remains the exclusive
 *            proprietary of MSP. Do not use without prior written permission
 *            by MSP
 * @license   https://smartmoov.solutions/license smartmoov
 * @link      https://resilier.online
 */
namespace prt\Spooling;

use App\Exceptions\PrtException;
use App\WebServices\PrtServices;
use Illuminate\Support\Facades\Log;

/**
 * Class PrtStatusLetter
 *
 * @category PrtStatusLetter
 * @package  App\Models\Spooling
 * @author   Stephane <smartmoov.solutions@gmail.com>
 * @license  https://smartmoov.solutions/license smartmoov
 * @link     https://resilier.online
 */
class PrtStatusLetter extends PrtServices
{
    /**
     * Récupère le status du spool.
     *
     * @param int $spoolId
     * @return array spoolId status statusDate
     * @throws PrtException
     */
    public function getSpoolStatusById(int $spoolId): array
    {
        $params = array(
            'spoolID' => $spoolId,
            'exceptions' => true
        );

        try {
            $spools = $this->soapClient->__soapCall('GetSpoolDataById', array($params));
            $spool = array(
                'spoolId'  => $spools->GetSpoolDataByIdResult->ID,
                'status' => $spools->GetSpoolDataByIdResult->Status,
                'statusDate' => $spools->GetSpoolDataByIdResult->StatusDate
            );
        } catch (\SoapFault $e) {
            Log::alert($this->soapClient->__getLastRequest());
            Log::alert($e->__toString());
            return null;
        }

        if ($spool['spoolId'] === 0) {
            Log::alert($this->soapClient->__getLastRequest());
            throw new PrtException('invalid_id');
        }
        return $spool;
    }
}