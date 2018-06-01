<?php
/**
 * Created by PhpStorm.
 * User: stéphane pau
 * Date: 20/05/2017
 * Time: 11:05
 */

namespace prt\Spooling;

use App\Models\Spool;


/**
 * Interface SpoolLetter
 * @package App\Models\Spooling
 */
interface SpoolLetter
{
    /**
     * Description du fichier du configuration du spool en cours
     *
     * @param string $file
     * @param string $filename
     * @return bool
     */
    public function configuration(string $file, string $filename): bool;

    /**
     * Envoie le fichier vers le serveur pour rematérialisation
     *
     * @param Spool $spool
     * @return int
     */
    public function sendDocument(Spool $spool): int;

}