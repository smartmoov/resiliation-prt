<?php
/**
 * Created by PhpStorm.
 * User: Stéphane Pau
 * Date: 20/05/2017
 * Time: 11:56
 * PHP Version 7.1
 */

namespace prt\Spooling;

use App\Exceptions\PrtException;
use App\Models\Spool;
use App\WebServices\PrtServices;
use Illuminate\Support\Facades\Log;

/**
 * Interface avec les API PRT
 *
 * Class PrtSpoolingLetter
 * @package App\Models\Spooling
 *
 * @method static \SoapClient BeginImport()
 */
class PrtSpoolingLetter extends PrtServices implements SpoolLetter
{
    /**
     * taille max binaire lu avec la fonction fget().
     */
    protected const SIZE_MAX_BINARY = 16384;

    /**
     * Vérifie que le fichier xml est valide
     *
     * @param string $xml
     * @param string $filename
     * @return bool
     * @throws PrtException
     */
    public function configuration(string $xml, string $filename): bool
    {
        // Validate xml file
        $reader = new \XMLReader();
        $xmlFile = $reader->XML($xml);
        if (false === $xmlFile) {
            throw new PrtException('invalid_xml');
        }
        return true;
    }

    /**
     * Envoi des éléments (xml+pdf) vers PRT
     *
     * @param Spool $spool
     * @return int PRT spoolID or -1 on error
     * @throws PrtException
     */
    public function sendDocument(Spool $spool): int
    {
        if (!$this->isAlive()) {
            throw new PrtException('service_offline');
        }

        return $this->uploadFiles($spool);
    }

    /**
     * Génère les envois du fichier xml et de(s) fichier(s) pdf vers PRT
     *
     * @param Spool $spool
     * @return int
     * @throws PrtException
     */
    protected function uploadFiles(Spool $spool): int
    {
        $importId = $this->soapClient->BeginImport();
        $this->transmitXml($importId->BeginImportResult, $spool->link_xml);
        $this->transmitPdf($importId->BeginImportResult, $spool->link_pdf);
        return $this->endImport($importId->BeginImportResult, $spool->link_xml);
    }

    /**
     * Envoi de fichiers vers PRT.
     *
     * @param string $importId
     * @param string $filename
     * @param string $path
     * @return bool
     * @throws PrtException, \SoapFault
     */
    protected function transmitFile(string $importId, string $filename, string $path): bool
    {
        try {
            $stream = fopen(storage_path('app/spools/' . $path . $filename), 'rb');
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return false;
        }

        $position = 0;
        while (!feof($stream)) {
            $readBytes = fread($stream, self::SIZE_MAX_BINARY);
            $params = array(
                'importID' => $importId,
                'fileName' => $filename,
                'chunk' => $readBytes,
                'position' => $position,
                'exceptions' => true
            );
            $result = $this->soapClient->__soapCall('UploadFileChunk', array($params));
            if (false === $result->UploadFileChunkResult) {
                throw new PrtException('upload_file');
            }
            $position += \strlen($readBytes);
        }
        fclose($stream);

        return true;
    }

    /**
     * Vérifie que les fichiers sont bien uploadés sur le serveur PRT
     *
     * @param string $importId
     * @param string $filename
     * @param string $path
     * @return bool
     * @throws PrtException, \SoapFault
     */
    protected function checkUpload(string $importId, string $filename, string $path): bool
    {
        $params = array(
            'importID' => $importId,
            'fileName' => $filename,
            'exceptions' => true,
            'md5hash' => md5_file(storage_path('app/spools/' . $path . $filename))
        );
        $result = $this->soapClient->__soapCall('CheckUploadedFile', array($params));
        if (false === $result->CheckUploadedFileResult) {
            Log::alert($this->soapClient->__getLastRequest());
            throw new PrtException('check_uploaded_file');
        }
        return true;
    }

    /**
     * Envoi des fichiers XML sur le serveur PRT
     *
     * @param string $importId
     * @param string $filename
     * @throws PrtException, \SoapFault
     */
    protected function transmitXml(string $importId, string $filename)
    {
        $sendXml = $this->transmitFile($importId, $filename, 'xml/');
        if (false === $sendXml) {
            Log::alert($this->soapClient->__getLastRequest());
            throw new PrtException('transmit_file');
        }
        $this->checkUpload($importId, $filename, 'xml/');
    }

    /**
     * Envoi des fichiers PDF vers le serveur PRT
     *
     * @param string $importId
     * @param string $filename
     * @throws PrtException, \SoapFault
     */
    protected function transmitPdf(string $importId, string $filename)
    {
        $sendPDF = $this->transmitFile($importId, $filename, 'pdf/');
        if (false === $sendPDF) {
            Log::alert($this->soapClient->__getLastRequest());
            throw new PrtException('transmit_file');
        }
        $this->checkUpload($importId, $filename, 'pdf/');
    }

    /**
     * PRT vérifie que le fichier xml et les fichiers pdf envoyés correspondent. En cas de succès, PRT renvoie le spoolID
     *
     * @param string $importId
     * @param string $filename
     * @return int
     * @throws PrtException, \SoapFault
     */
    protected function endImport(string $importId, string $filename): int
    {
        $params = array(
            'importID' => $importId,
            'exceptions' => true,
            'xmlFileName' => $filename
        );

        $result = $this->soapClient->__soapCall('EndImport', array($params));

        if ($result->EndImportResult !== 'OK') {
            Log::alert($this->soapClient->__getLastRequest());
            Log::alert($result->badDocs->BadDoc->ErrorMessage);
            throw new PrtException('end_import');
        }
        return $result->spoolID;
    }
}