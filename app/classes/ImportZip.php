<?php
/**
 * \Elabftw\Elabftw\ImportZip
 *
 * @author Nicolas CARPi <nicolas.carpi@curie.fr>
 * @copyright 2012 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */
namespace Elabftw\Elabftw;

use Exception;
use ZipArchive;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use FileSystemIterator;

/**
 * Import a .elabftw.zip file into the database.
 */
class ImportZip extends Import
{
    /** instance of Entity */
    private $Entity;

    /** instance of Uploads */
    private $Uploads;

    /** instance of Tags */
    private $Tags;

    /** pdo object */
    private $pdo;

    /** number of item we have inserted */
    public $inserted = 0;
    /** the folder where we extract the zip */
    private $tmpPath;
    /** an array with the data we want to import */
    private $json;

    /** the target item type */
    private $target;

    /** experiments or items */
    private $type = 'items';

    /** the newly created id of the imported item */
    private $newItemId;

    /**
     * Constructor
     *
     */
    public function __construct()
    {
        $this->pdo = Db::getConnection();

        $this->checkFileReadable();
        $this->checkMimeType();
        $this->target = $this->getTarget();
        // this is where we will extract the zip
        $this->tmpPath = ELAB_ROOT . 'uploads/tmp/' . uniqid();
        if (!mkdir($this->tmpPath)) {
            throw new Exception('Cannot create temporary folder');
        }

        $this->openFile();
        $this->readJson();
        $this->importAll();
    }

    /**
     * Extract the zip to the temporary folder
     *
     * @throws Exception if it cannot open the zip
     * @return bool
     */
    protected function openFile()
    {
        $zip = new ZipArchive;
        return $zip->open($this->getFilePath()) && $zip->extractTo($this->tmpPath);
    }

    /**
     * We get all the info we need from the embedded .json file
     *
     */
    private function readJson()
    {
        $file = $this->tmpPath . "/.elabftw.json";
        $content = file_get_contents($file);
        $this->json = json_decode($content, true);
        if (isset($this->json[0]['elabid'])) {
            $this->type = 'experiments';
        }
    }

    /**
     * Select a status for our experiments.
     *
     * @return int The default status of the team
     */
    private function getDefaultStatus()
    {
        $sql = 'SELECT id FROM status WHERE team = :team AND is_default = 1';
        $req = $this->pdo->prepare($sql);
        $req->bindParam(':team', $_SESSION['team_id']);
        $req->execute();
        return $req->fetchColumn();
    }


    /**
     * The main SQL to create a new item with the title and body we have
     *
     * @param array $item the item to insert
     * @throws Exception if SQL request failed
     */
    private function dbInsert($item)
    {
        $sql = "INSERT INTO items(team, title, date, body, userid, type)
            VALUES(:team, :title, :date, :body, :userid, :type)";

        if ($this->type === 'experiments') {
            $sql = "INSERT into experiments(team, title, date, body, userid, visibility, status, elabid)
                VALUES(:team, :title, :date, :body, :userid, :visibility, :status, :elabid)";
        }
        $req = $this->pdo->prepare($sql);
        $req->bindParam(':team', $_SESSION['team_id']);
        $req->bindParam(':title', $item['title']);
        $req->bindParam(':date', $item['date']);
        $req->bindParam(':body', $item['body']);
        if ($this->type === 'items') {
            $req->bindParam(':userid', $_SESSION['userid']);
            $req->bindParam(':type', $this->target);
        } else {
            $req->bindValue(':visibility', 'team');
            $req->bindValue(':status', $this->getDefaultStatus());
            $req->bindParam(':userid', $this->target, \PDO::PARAM_INT);
            $req->bindParam(':elabid', $item['elabid']);
        }


        if (!$req->execute()) {
            throw new Exception('Cannot import in database!');
        }
        // needed in importFile()
        $this->newItemId = $this->pdo->lastInsertId();

        // create necessary objects
        $Users = new Users($_SESSION['userid']);
        if ($this->type === 'experiments') {
            $this->Entity = new Experiments($Users, $this->newItemId);
        } else {
            $this->Entity = new Database($Users, $this->newItemId);
        }
        $this->Uploads = new Uploads($this->Entity);
        $this->Tags = new Tags($this->Entity);

        if (strlen($item['tags']) > 1) {
            $this->tagsDbInsert($item['tags']);
        }
    }

    /**
     * Loop over the tags and insert them for the new entity
     *
     * @param string $tags the tags string separated by '|'
     */
    private function tagsDbInsert($tags)
    {
        $tagsArr = explode('|', $tags);
        foreach ($tagsArr as $tag) {
            $this->Tags->create($tag);
        }
    }

    /**
     * Loop the json and import the items.
     *
     */
    private function importAll()
    {
        foreach ($this->json as $item) {

            $this->dbInsert($item);

            // upload the attached files
            if (is_array($item['uploads'])) {
                $titlePath = preg_replace('/[^A-Za-z0-9]/', '_', stripslashes($item['title']));
                foreach ($item['uploads'] as $file) {
                    if ($this->type === 'experiments') {
                        $filePath = $this->tmpPath . '/' .
                            $item['date'] . '-' . $titlePath . '/' . $file['real_name'];
                    } else {
                        $filePath = $this->tmpPath . '/' .
                            $item['category'] . ' - ' . $titlePath . '/' . $file['real_name'];
                    }

                    /**
                     * Ok so right now if you have several files with the same name, the real_name in the json will be
                     * the same, but the extracted file will have a 1_ in front of the name. So here we will skip the
                     * import but this should be handled. One day. Maybe.
                     */
                    if (is_readable($filePath)) {
                        $this->Uploads->createFromLocalFile($filePath, $file['comment']);
                    }
                }
            }
            $this->inserted += 1;
        }
    }

    /**
     * Cleanup : remove the temporary folder created
     *
     */
    public function __destruct()
    {
        // first remove content
        $di = new \RecursiveDirectoryIterator($this->tmpPath, \FilesystemIterator::SKIP_DOTS);
        $ri = new \RecursiveIteratorIterator($di, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($ri as $file) {
            $file->isDir() ? rmdir($file) : unlink($file);
        }
        // and remove folder itself
        rmdir($this->tmpPath);
    }
}
