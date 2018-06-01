<?php
/**
 * Created by PhpStorm.
 * User: vivien
 * Date: 23/10/17
 * Time: 09:30
 */

namespace prt\Router;

use App\Events\Accepted;
use App\Events\Printing;
use App\Events\Routed;
use App\Events\Sent;
use App\Exceptions\PrtException;
use App\Models\Spool;
use App\Models\Spooling\PrtSpoolingLetter;
use App\Models\Spooling\PrtStatusLetter;
use App\Models\Tracking;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Models\Router\Router;

/**
 * Class Prt
 *
 * @package App\Models\Router
 *
 * @property int $id
 * @property int|null $spool_prt numéro de spool attribué par PRT
 * @property string $num_unique numéro de spool unique attribué par nous
 * @property string $link_ini lien vers le fichier pjs maileva
 * @property string $link_xml lien vers le fichier de configuration PRT
 * @property string $link_pdf lien vers le fichier spool pour envoi à PRT
 * @property int $completed indique si le tracking du spool est terminé
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Letter[] $letters
 *
 * @method static Builder|Prt whereCompleted($value)
 * @method static Builder|Prt whereCreatedAt($value)
 * @method static Builder|Prt whereId($value)
 * @method static Builder|Prt whereLinkIni($value)
 * @method static Builder|Prt whereLinkPdf($value)
 * @method static Builder|Prt whereLinkXml($value)
 * @method static Builder|Prt whereNumUnique($value)
 * @method static Builder|Prt whereSpoolPrt($value)
 * @method static Builder|Prt whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Prt extends Router
{
    /**
     * @param Spool $spool
     * @return mixed|void
     * @throws \App\Exceptions\PrtException
     */
    public function sendDocument(Spool $spool): void
    {
        $prt = new PrtSpoolingLetter();
        try {
            $prt->configuration($spool->xml, $spool->getFilenameXml());
            $spool->spool_prt = $prt->sendDocument($spool);
            $spool->save();
        } catch (\Exception $e) {
            Log::critical("Can't send xml file: " . $e->getMessage());
            die;
        }
    }

    /**
     * Update le suvi pour un envoi vers PRT
     */
    public function updateTracking(): void
    {
        $spools = Spool::where('completed', false)->where('created_at', '>', new Carbon('last month'))->get();
        $spools->map(function (Spool $spool) {
            if ($spool->spool_prt !== null) {
                try {
                    // Appel API PRT
                    $prtTracking = $this->tracking($spool->spool_prt);
                    //Update le tracking et envoie des mails pour chaque étapes
                    $this->event($prtTracking);
                } catch (ModelNotFoundException $e) {
                    Log::error($e->getMessage() . '- fichier' . __FILE__ . '- line' . __LINE__);
                } catch (\InvalidArgumentException $e) {
                    Log::error($e->getMessage() . '- fichier' . __FILE__ . '- line' . __LINE__);
                }
            }
        });

        $this->trackingLaPoste();
    }

    /**
     * Envoie du status du spool vers TrackingRepository
     * catch les erreurs
     *
     * @param int $spoolId
     * @return array spoolId status statusDate
     */
    private function tracking(int $spoolId): array
    {
        $prt = new PrtStatusLetter();

        try {
            $spool = $prt->getSpoolStatusById($spoolId);
        } catch (PrtException $e) { // Connection au serveur PRT impossible
            Log::alert('cannot connect to PRT server' . $e->getMessage());
            return ['error' => 'cannot connect to server'];
        }
        return $spool;
    }

    /**
     * Envoi de mail pour le suivi
     *
     * @param array $spool
     */
    private function event(array $spool): void
    {
        switch ($spool['status']) {
            case Tracking::ACCEPTED:
                event(new Accepted($spool['date']));
                break;
            case Tracking::ROUTED:
                event(new Routed($spool['date']));
                break;
            case Tracking::PRINTING:
                event(new Printing($spool['date']));
                break;
            case Tracking::DELIVERED:
                event(new Sent($spool['date']));
                break;
        }
    }

    /**
     * Création du XML.
     *
     * @param int $count
     * @param Spool $spool
     * @return string renvoi render()
     */
    public function createXml(int $count, Spool $spool): string
    {
        $spooling = $spool->makeXmlParam();
        return view(Spool::TEMPLATE_FILE, ['spool' => $spooling])->render();

    }

    /**
     * Sauvegarde des fichiers XML dans le répertoire storage/app/spools/xml.
     *
     * @param Spool $spool
     * @param string $content
     * @return bool
     */
    public function saveXml(Spool $spool, string $content): bool
    {
        try {
            $filename = $spool->getFilenameXml();
            return Storage::put(Spool::STORAGE_DIR . $filename, $content);
        } catch (\Exception $e) {
            Log::critical("Can't save xml file: " . $e->getMessage());
            die;
        }
    }

}