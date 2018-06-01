<?php
/**
 * Appel SOAP vers PRT pour la récupération des preuve de dépôt et accusé de
 * réception sur les serveurs de PRT. Stockage des fichiers récupérés sur nos
 * serveurs
 *
 * Copyright (c) 2017. by MSP
 * This file remains the exclusive proprietary of MSP. Do not use without prior written permission by MSP
 */

/**
 * Created by PhpStorm.
 * User: stephanepau
 * Date: 09/09/2017
 * Time: 19:45
 * PHP version 7.1
 *
 * @category Spooling
 * @package  Resiliation
 * @author   Stephane <smartmoov.solutions@gmail.com>
 * @license  https://smartmoov.solutions/license smartmoov
 * @link     https://resilier.online
 */

namespace prt\Spooling;

use App\Exceptions\PrtException;
use App\Models\Letter;
use App\Models\Spool;
use App\Repositories\SpoolRepository;
use App\WebServices\PrtServices;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Class PrtDocumentsLetter
 *
 * @category Spooling
 * @package  App\Models\Spooling
 * @author   Stephane <smartmoov.solutions@gmail.com>
 * @license  https://smartmoov.solutions/license smartmoov
 * @link     https://resilier.online
 */
class PrtDocumentsLetter extends PrtServices
{
    /**
     * @var $spool
     */
    protected $spool;

    /**
     * PrtDocumentsLetter constructor.
     *
     * @param Spool $spool
     */
    public function __construct(Spool $spool)
    {
        parent::__construct();
        $this->spool = $spool;
    }

    /**
     * @return Collection
     */
    public function spoolsToDownload(): ?Collection
    {
        $this->isAlive();

        $params = array(
            'exceptions' => true
        );

        $spools = $this->soapClient->__soapCall('GetSpoolsToDownload', array($params));
        if ($spools === null || $spools->count() === 0) {
            Log::notice('no documents to download from PRT servers');
            return null;
        }
        return $spools;
    }

    /**
     * @param int $documentId
     *
     */
    public function downloadDocument(int $documentId): void
    {
        $params = array(
            'spoolID' => $documentId,
            'exceptions' => true
        );

        $downloadInfo = $this->soapClient->__soapCall('BeginDownload', array($params));
        //dd($downloadInfo);
        $fileName = $downloadInfo->BeginDownloadResult->fileName;
        $downloadFile = $this->downloadFile($downloadInfo, storage_path() . Letter::STORAGE_DIR);
        $this->endDownload($documentId, $downloadFile);
    }

    /**
     * Récupère l'accusé de réception du spool
     *
     * @param int $spoolId
     * @return array docId status
     */
    public function downloadReceipt(int $spoolId): array
    {
        $params = array(
            'spoolID' => $spoolId,
            'exceptions' => true
        );
        $docs = $this->soapClient->__soapCall('GetImportedDocumentData', array($params));
        $doc = array(
            'docId' => $docs->GetImportedDocumentDataResult->Document->ID,
            'status' => $docs->GetImportedDocumentDataResult->Document->Status,
            'trackingCode' => $docs->GetImportedDocumentDataResult->Document->TrackingCode,
            //'customerSpoolId' => $docs->GetImportedDocumentDataResult->Document->CustomerDocID,
        );

        //update le numéro de recommandée
        if ($docs != null) {
            if ($doc['status'] === 'Delivered') {
                $spool = new SpoolRepository($this->spool);
                $spool->updateNumTracking($doc['trackingCode'],$params['spoolID']);
            }
        }

        if ($docs !== null) {
            if ($doc['status'] === 'Consigned' || $doc['status'] === 'NotConsigned') {

                $params = array(
                    'spoolID' => $doc['docId'],
                    'exceptions' => true
                );

                $mail = $this->soapClient->__soapCall('DownloadMailReceiptByDocID', array($params));
                //var_dump($mail);
                //return $mail->DownloadMailReceiptByDocIDResult;
            }
        }
        $this->storeFile();
        //var_dump($docs);
        //echo 'doc----------------------------------------';
        //var_dump($doc);
        return $doc;
    }


    public function storeFile()
    {
        $buffer = fopen('/var/www/html/resilier/storage/app/receipt/test.txt','a+');
        fwrite($buffer,'test');
        fclose($buffer);
    }

    /**
     * Téléchargement sur les serveurs PRT
     *
     * @param \stdClass $downloadInfo
     * @param string $localFile
     * @return \stdClass
     * @throws PrtException
     */
    protected function downloadFile(\stdClass $downloadInfo, string $localFile): \stdClass
    {
        $fileSize = $downloadInfo->BeginDownloadResult->filseSize;
        $downloadId = $downloadInfo->BeginDownloadResult->ID;
        $fileName = $downloadInfo->BeginDownloadResult->fileName;

        $chunkSize = 500 * 1024;
        $position = 0;
        do {
            $params = array(
                'downloadID' => $downloadId,
                'chunkSize' => $chunkSize,
                'position' => $position,
                'exceptions' => true
            );
            $result = $this->soapClient->__soapCall('DownloadFileChunk', array($params));
            if (false === $result->DownloadFileChunkResult) {
                throw new PrtException('download_file');
            }
            // todo: write file to disk
            //Storage::disk('app')->put('file.app', $content);
            //$content = Storage::disk('app')->get('file.app');

            $position += \strlen($chunkSize);
        } while ($result->DownloadFileChunkResult->buffer > 0);

        return $result->DownloadFileChunkResult;
    }

    /**
     *
     * @param $documentId
     * @param \stdClass $downloadFile
     * @return mixed
     */
    protected function endDownload($documentId, \stdClass $downloadFile): \stdClass
    {
        $downloadId = $downloadFile->DownloadFileChunkResult->downloadID;

        $params = array(
            'SpoolID' => $documentId,
            'downloadID' => $downloadId,
            'md5hash' => false,
            'exceptions' => true
        );
        $result = $this->soapClient->__soapCall('EndDownload', array($params));

        return $result->EndDownloadResult;
    }
}