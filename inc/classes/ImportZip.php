<?php
/**
 * \Elabftw\Elabftw\ImportZip
 *
 * @author Nicolas CARPi <nicolas.carpi@curie.fr>
 * @copyright 2012 Nicolas CARPi
 * @see http://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */
namespace Elabftw\Elabftw;

use \Exception;
use \ZipArchive;
use \RecursiveIteratorIterator;
use \RecursiveDirectoryIterator;
use \FileSystemIterator;

/**
 * Import a .elabftw.zip file into the database.
 */
class ImportZip
{
    /** pdo object */
    private $pdo;

    /** number of item we have inserted */
    public $inserted = 0;
    /** the folder where we extract the zip */
    private $tmpPath;
    /** the name of the temporary uploaded input zip */
    private $fileTmpName;
    /** an array with the data we want to import */
    private $json;

    /** the target item type */
    private $itemType;
    /** title of new item */
    private $title;
    /** body of new item */
    private $body;
    /**
     * the newly created id of the imported item
     * we need it for linking attached file(s) to the the new item
     */
    private $newItemId;

    /**
     * Need the path to zip tmp_name, the type and the db object
     *
     * @param string $zipfile Path to temporary name of uploaded zip
     * @param int itemType the type of item we want in the end
     * @param Db $db An instance of the Db class
     */
    public function __construct($zipfile, $itemType, Db $db)
    {

        $this->pdo = $db->connect();

        $this->fileTmpName = $zipfile;
        $this->itemType = $itemType;
        // this is where we will extract the zip
        $this->tmpPath = ELAB_ROOT . 'uploads/tmp/' . uniqid();
        if (!mkdir($this->tmpPath)) {
            throw new Exception('Cannot create temporary folder');
        }

        $this->extractZip();
        $this->readJson();
        $this->importAll();
    }

    /**
     * Extract the zip to the temporary folder
     *
     * @throws Exception if it cannot open the zip
     * @return bool
     */
    private function extractZip()
    {
        $zip = new \ZipArchive;
        if ($zip->open($this->fileTmpName) && $zip->extractTo($this->tmpPath)) {
            return true;
        } else {
            throw new Exception('Cannot open zip file!');
        }
    }

    /**
     * We get all the info we need from the embedded .json file
     *
     * @throws Exception if we try to import an experiment
     */
    private function readJson()
    {
        $file = $this->tmpPath . "/.elabftw.json";
        $content = file_get_contents($file);
        $this->json = json_decode($content, true);
        // we can only import database items, not experiments
        if ($this->json[0]['type'] === 'experiments') {
            throw new Exception('Cannot import an experiment!');
        }
    }

    /**
     * The main SQL to create a new item with the title and body we have
     *
     * @throws Exception if SQL request failed
     */
    private function importData()
    {
        $sql = "INSERT INTO items(team, title, date, body, userid, type) VALUES(:team, :title, :date, :body, :userid, :type)";
        $req = $this->pdo->prepare($sql);
        $req->bindParam(':team', $_SESSION['team_id'], \PDO::PARAM_INT);
        $req->bindParam(':title', $this->title);
        $req->bindParam(':date', kdate());
        $req->bindParam(':body', $this->body);
        $req->bindParam(':userid', $_SESSION['userid'], \PDO::PARAM_INT);
        $req->bindParam(':type', $this->itemType);

        if (!$req->execute()) {
            throw new Exception('Cannot import in database!');
        }
        // needed in importFile()
        $this->newItemId = $this->pdo->lastInsertId();
    }

    /**
     * If files are attached we want them!
     *
     * @throws Exception if it cannot rename the file or SQL request failed
     * @param string $file The path of the file in the archive
     */
    private function importFile($file)
    {
        // first move the file to the uploads folder
        $longName = hash("sha512", uniqid(rand(), true)) . '.' . (new \Elabftw\Elabftw\Tools)->getExt($file);
        $newPath = ELAB_ROOT . 'uploads/' . $longName;
        if (!rename($this->tmpPath . '/' . $file, $newPath)) {
            throw new Exception('Cannot rename file!');
        }

        // make md5sum
        $md5 = hash_file('md5', $newPath);

        // now insert it in sql
        $sql = "INSERT INTO uploads(
            real_name,
            long_name,
            comment,
            item_id,
            userid,
            type,
            md5
        ) VALUES(
            :real_name,
            :long_name,
            :comment,
            :item_id,
            :userid,
            :type,
            :md5
        )";

        $req = $this->pdo->prepare($sql);
        $req->bindParam(':real_name', basename($file));
        $req->bindParam(':long_name', $longName);
        $req->bindValue(':comment', 'Click to add a comment');
        $req->bindParam(':item_id', $this->newItemId);
        $req->bindParam(':userid', $_SESSION['userid']);
        $req->bindValue(':type', 'items');
        $req->bindParam(':md5', $md5);

        if (!$req->execute()) {
            throw new Exception('Cannot import in database!');
        }
    }

    /**
     * Loop the json and import the items.
     *
     */
    private function importAll()
    {
        foreach ($this->json as $item) {
            $this->title = $item['title'];
            $this->body = $item['body'];
            $this->importData();
            if (is_array($item['files'])) {
                foreach ($item['files'] as $file) {
                    $this->importFile($file);
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
