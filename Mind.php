<?php

/**
 *
 * @package    Mind
 * @version    Release: 6.0.7
 * @license    GPL3
 * @author     Ali YILMAZ <aliyilmaz.work@gmail.com>
 * @category   Php Framework, Design pattern builder for PHP.
 * @link       https://github.com/aliyilmaz/Mind
 *
 */

/**
 * Class Mind
 */
class Mind
{
    public $session_path    =   null; // ./session/ or null(system path)

    public  $post           =   [];
    public  $base_url;
    public  $page_uri       =   '';
    public  $page_current   =   '';
    public  $page_back      =   '';
    public  $project_path   =   '';
    public  $project_domain =   '';
    public  $timezone       =   'Europe/Istanbul';
    public  $timestamp;
    public  $lang           =   [
        'table'     =>  'translations',
        'column'    =>  'lang',
        'haystack'  =>  'name',
        'return'    =>  'text',
        'lang'      =>  'EN'
    ];

    public $error_status                =   false;
    public $errors                      =   [];
    public $error_permission_message    =   null;

    private $conf           =   [];
    private $db             =   [
        'drive'     =>  'mysql', // mysql, sqlite, sqlsrv
        'host'      =>  'localhost', // for sqlsrv: www.example.com\\MSSQLSERVER,'.(int)1433
        'dbname'    =>  'mydb', // mydb, app/migration/mydb.sqlite
        'username'  =>  'root',
        'password'  =>  '',
        'charset'   =>  'utf8mb4'
    ];
    private $sql            =   '';
    private $conn;
    public $monitor        =   [];
    public $parent_class;
    
    /**
     * Mind constructor.
     * @param array $conf
     */
    public function __construct($conf = array()){
        ob_start();
        
        /* error settings */
        error_reporting(-1);
        error_reporting(E_ALL);
        ini_set('display_errors', 1);

        /* server limit settings */
        ini_set('memory_limit', '-1');
        if(!in_array('set_time_limit', explode(',', str_replace(' ', '', ini_get('disable_functions'))))) {
            set_time_limit(0);
        }
        
        /* Creating the timestamp */
        date_default_timezone_set($this->timezone);
        $this->timestamp = date("Y-m-d H:i:s");

        $this->parent_class = get_parent_class($this);
        $this->conf = (isset($conf)) ? $conf : ['db'=>$this->db];
        
        /* Database connection */
        $dbStatus = true;
        if(isset($conf['db'])){
            $dbStatus = (($conf['db'] == '') OR (is_array($conf['db']) AND empty($conf['db']))) ? false : true;
        }
        if($dbStatus){
            $this->dbConnect($conf);
        }
    
        /* Interpreting Get, Post, and Files requests */
        $this->request();

        /* Enabling session management */
        $this->session_path = isset($conf['session_path']) ? $conf['session_path'] : $this->session_path;
        $this->session_check();

        /* Activating the firewall */
        $this->firewall($conf);

        /* Providing translation settings */
        if(isset($conf['translate'])){
            $this->lang['table']    = (isset($conf['translate']['table'])) ?: $conf['translate']['table'];
            $this->lang['column']   = (isset($conf['translate']['column'])) ?: $conf['translate']['column'];
            $this->lang['haystack'] = (isset($conf['translate']['haystack'])) ?: $conf['translate']['haystack'];
            $this->lang['return']   = (isset($conf['translate']['return'])) ?: $conf['translate']['return'];
            $this->lang['lang']     = (isset($conf['translate']['lang'])) ?: $conf['translate']['lang'];
        }

        /* Determining the home directory path (Mind.php) */
        $baseDir = $this->get_absolute_path(dirname($_SERVER['SCRIPT_NAME']));
        $this->base_url = (empty($baseDir)) ? '/' : '/'.$baseDir.'/';

        /* Determining the previous page address */
        $this->page_back = (isset($_SERVER['HTTP_REFERER'])) ? $_SERVER['HTTP_REFERER'] : $this->page_current;

        // Domain with a prefix and containing the project directory name
        $this->project_path = ($_SERVER['SERVER_NAME'].'/' != $this->base_url) ? $_SERVER['SERVER_NAME'].$this->base_url : $this->base_url;
        $this->project_path = ($this->is_ssl())?'https://'.$this->project_path : 'http://'.$this->project_path;      
        
        // If the port is a compulsory address, the port is added.
        if(!in_array($_SERVER['SERVER_PORT'], ['80', '443'])){
            $this->project_path = rtrim($this->project_path, "/").':'.$_SERVER['SERVER_PORT'].'/';
        }

        // Like example.com or test.example.com
        $this->project_domain = $this->normalizeDomain($this->project_path);

        $thought = (!empty($conf['thought'])) ? $conf['thought'] : null;
        if(!is_null($thought)) { $this->addLayer($thought); }

    }

    public function __destruct()
    {
        $this->conn = null;
        $this->monitor = [];
        if($this->error_status){ $this->abort('404', 'Not Found.'); }
    }

    /**
     * @param array|null $conf
     */
    public function dbConnect($conf = array()){

        if(isset($conf['db']['drive'])){ $this->db['drive'] = $conf['db']['drive'];}
        if(isset($conf['db']['host'])){ $this->db['host'] = $conf['db']['host'];}
        if(isset($conf['db']['dbname'])){ $this->db['dbname'] = $conf['db']['dbname'];}
        if(isset($conf['db']['username'])){ $this->db['username'] = $conf['db']['username'];}
        if(isset($conf['db']['password'])){ $this->db['password'] = $conf['db']['password'];}     
        if(isset($conf['db']['charset'])){ $this->db['charset'] = $conf['db']['charset'];}     

        try {

            switch ($this->db['drive']) {
                case 'mysql': 
                    $this->conn = new PDO($this->db['drive'].':host='.$this->db['host'].';charset='.$this->db['charset'].';', $this->db['username'], $this->db['password']);
                break;
                case 'sqlsrv':
                    $this->conn = new PDO($this->db['drive'].':Server='.$this->db['host'].';', $this->db['username'], $this->db['password']);
                break;
                case 'sqlite':
                    $this->conn = new PDO($this->db['drive'].':'.$this->db['dbname']);
                break;
                default:
                $this->abort('503', 'Invalid database driver.');
            }

            if(!empty($this->conf)){
                if(!$this->is_db($this->db['dbname'])){ $this->dbCreate($this->db['dbname']); } 
            }

            if(in_array($this->db['drive'], ['mysql', 'sqlsrv'])){
                $this->selectDB($this->db['dbname']);           
            }        
            
        } catch ( PDOException $th ){

            $this->monitor['errors'][] = $th->getMessage();

            if(stristr($th->errorInfo[2], 'php_network_getaddresses: getaddrinfo')){
                $this->abort('502', 'Invalid database connection address.');
            }
            if(stristr($th->errorInfo[2], 'Too many connections')){
                $this->abort('401', 'We have restricted your access due to intensity.');
            }

            if(stristr($th->errorInfo[2], 'Access denied for user')){
                $this->abort('401', 'Unauthorized user information.');
            }
            
            if(
                stristr($th->errorInfo[2], 'No such file or directory') OR 
                stristr($th->errorInfo[2], 'Connection refused') OR 
                stristr($th->getMessage(), 'No connection could be made because the target machine actively refused it'))
            {
                $this->abort('406', 'The database driver does not work.');
            }
        }

        return $this;
    }

    /**
     * Database selector.
     *
     * @param string $dbName
     * @return bool
     */
    public function selectDB($dbName){
        if($this->is_db($dbName)){

            switch ($this->db['drive']) {
                case 'mysql':                    
                    $this->conn->exec("USE ".$dbName);
                break;
            }
        } else {
            return false;
        }
        
        $this->conn->setAttribute( PDO::ATTR_EMULATE_PREPARES, true );
        $this->conn->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT );
        $this->conn->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING );
        $this->conn->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
        return true;
    }

    /**
     * Lists the databases.
     *
     * @return array
     */
    public function dbList(){

        $dbnames = array();

        switch ($this->db['drive']) {
            case 'mysql':
                $sql     = 'SHOW DATABASES';

                try{
                    $query = $this->conn->query($sql, PDO::FETCH_ASSOC);

                    foreach ( $query as $database ) {
                        $dbnames[] = implode('', $database);
                    }

                    return $dbnames;

                } catch (Exception $e){
                    return $dbnames;
                }

            break;
            
            case 'sqlite':
                return $this->ffsearch('./', '*.sqlite');
            break;
        }
        
    }

    /**
     * Lists database tables.
     *
     * @param string $dbName
     * @return array
     */
    public function tableList($dbname=null){

        $query = [];
        $tblNames = array();

        try{

            switch ($this->db['drive']) {
                case 'mysql':
                    $dbParameter = (!is_null($dbname)) ? ' FROM '.$dbname : '';
                    $sql = 'SHOW TABLES'.$dbParameter;
                    $query = $this->conn->query($sql, PDO::FETCH_ASSOC);
                    foreach ($query as $tblName){
                        $tblNames[] = implode('', $tblName);
                    }
                break;
                case 'sqlsrv':

                    $this->selectDB($dbname);
                    $sql = 'SELECT name, type FROM sys.tables';
                    $query = $this->conn->query($sql);
                    $tblNames = $query->fetchAll(PDO::FETCH_COLUMN);
                break;
                
                case 'sqlite':
                    $statement = $this->conn->query("SELECT name FROM sqlite_master WHERE type='table';");
                    $query = $statement->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($query as $tblName){
                        $tblNames[] = $tblName['name'];
                    }
                break;
            }

        } catch (Exception $e){
            return $tblNames;
        }

        return $tblNames;
    }

     /**
     * Lists table columns.
     *
     * @param string $tblName
     * @return array
     */
    public function columnList($tblName){

        $columns = array();

        switch ($this->db['drive']) {
            case 'mysql':
                $sql = 'SHOW COLUMNS FROM `' . $tblName.'`';

                try{
                    $query = $this->conn->query($sql, PDO::FETCH_ASSOC);

                    $columns = array();

                    foreach ( $query as $column ) {

                        $columns[] = $column['Field'];
                    }

                } catch (Exception $e){
                    return $columns;
                }
            break;
            case 'sqlite':
                
                $statement = $this->conn->query('PRAGMA TABLE_INFO(`'. $tblName . '`)');
                foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $key => $column) {
                    $columns[] = $column['name'];
                }
            break;
        }

        return $columns;
        
    }

     /**
     * Creating a database.
     *
     * @param mixed $dbname
     * @return bool
     */
    public function dbCreate($dbname){

        $dbnames = array();
        $dbnames = (is_array($dbname)) ? $dbname : [$dbname];

        try{
            foreach ( $dbnames as $dbname ) {

                switch ($this->db['drive']) {
                    case 'mysql':
                        $sql = "CREATE DATABASE";
                        $sql .= " ".$dbname." DEFAULT CHARSET=".$this->db['charset'];
                        if(!$this->conn->query($sql)){ return false; }
                    break;
                    
                    case 'sqlite':
                        if(!file_exists($dbname) AND $dbname !== $this->db['dbname']){
                            $this->dbConnect(['db'=>['dbname'=>$dbname]]);
                        }
                    break;
                }
                if($dbname === $this->db['dbname']){ 
                    $this->dbConnect(['db'=>['dbname'=>$dbname]]); 
                }

            }
            
        }catch (Exception $e){
            return false;
        }

        return true;
    }

    /**
     * Creating a table.
     *
     * @param string $tblName
     * @param array $scheme
     * @return bool
     */
    public function tableCreate($tblName, $scheme){

        if(is_array($scheme) AND !$this->is_table($tblName)){
            // switch
            $engine = '';
            switch ($this->db['drive']) {
                case 'mysql':
                    $engine = " ENGINE = INNODB";
                break;
            }
         
            try{

                $sql = "CREATE TABLE `".$tblName."` ";
                $sql .= "(\n\t";
                $sql .= implode(",\n\t", $this->columnSqlMaker($scheme));
                $sql .= "\n)".$engine.";";

                if(!$this->conn->query($sql)){
                    return false;
                }
                return true;
            }catch (Exception $e){
                return false;
            }
        }

        return false;

    }

     /**
     * Creating a column.
     *
     * @param string $tblName
     * @param array $scheme
     * @return bool
     */
    public function columnCreate($tblName, $scheme){

        if($this->is_table($tblName)){

            try{

                $sql = "ALTER TABLE\n";
                $sql .= "\t`".$tblName."`\n";
                $sql .= implode(",\n\t", $this->columnSqlMaker($scheme, 'columnCreate'));

                if(!$this->conn->query($sql)){
                    return false;
                } else {
                    return true;
                }

            }catch (Exception $e){
                return false;
            }
        }

        return false;
    }

    /**
     * Delete database.
     *
     * @param mixed $dbname
     * @return bool
     */
    public function dbDelete($dbname){

        $dbnames = array();

        if(is_array($dbname)){
            foreach ($dbname as $key => $value) {
                $dbnames[] = $value;
            }
        } else {
            $dbnames[] = $dbname;
        }
        foreach ($dbnames as $dbname) {

            if(!$this->is_db($dbname)){

                return false;

            }

            switch ($this->db['drive']) {
                case 'mysql':
                    try{

                        $sql = "DROP DATABASE";
                        $sql .= " ".$dbname;
        
                        $query = $this->conn->query($sql);
                        if(!$query){
                            return false;
                        }
                    }catch (Exception $e){
                        return false;
                    }
                break;
                
                case 'sqlite':
                    if(file_exists($dbname)){
                        unlink($dbname);
                    } else {
                        return false;
                    }
                   
                break;
            }
            

        }
        return true;
    }

    /**
     * Table delete.
     *
     * @param mixed $tblName
     * @return bool
     */
    public function tableDelete($tblName){

        $tblNames = array();

        if(is_array($tblName)){
            foreach ($tblName as $key => $value) {
                $tblNames[] = $value;
            }
        } else {
            $tblNames[] = $tblName;
        }
        foreach ($tblNames as $tblName) {

            if(!$this->is_table($tblName)){

                return false;

            }

            try{

                $sql = "DROP TABLE";
                $sql .=" `".$tblName.'`';

                $query = $this->conn->query($sql);
                if(!$query){
                    return false;
                }
            }catch (Exception $e){
                return false;
            }
        }
        return true;
    }

    /**
     * Column delete.
     *
     * @param string $tblName
     * @param mixed $column
     * @return bool
     */
    public function columnDelete($tblName, $column = null){

        $columnList = $this->columnList($tblName);

        $columns = array();
        $columns = (!is_null($column) AND is_array($column)) ? $column : $columns; // array
        $columns = (!is_null($column) AND !is_array($column)) ? [$column] : $columns; // string

        switch ($this->db['drive']) {
            case 'mysql':
                $sql = "ALTER TABLE `".$tblName."`";
                foreach ($columns as $column) {

                    if(!in_array($column, $columnList)){
                        return false;
                    }
                    $dropColumns[] = "DROP COLUMN `".$column."`";
                }

                try{
                    $sql .= " ".implode(', ', $dropColumns);
                    $query = $this->conn->query($sql);
                    if(!$query){
                        return false;
                    }
                }catch (Exception $e){
                    return false;
                }
            break;
            
            case 'sqlite':
                $output = [];
                
                $data = $this->getData($tblName);
                foreach ($data as $key => $row) {
                    foreach ($columns as $key => $column) {
                        if(in_array($column, array_keys($row)) AND in_array($column, $columnList)){
                            unset($row[$column]);
                        }
                    }
                    $output['data'][] = $row;
                }

                try{
                    
                    $scheme = $this->tableInterpriter($tblName, $columns);
                    $this->tableDelete($tblName);
                    $this->tableCreate($tblName, $scheme);
                    if(!empty($output['data'])){
                        $this->insert($tblName, $output['data']);
                    }
                    
                }catch (Exception $e){
                    return false;
                }
                
            break;
        }
        
        return true;
    }

    /**
     * Clear database.
     *
     * @param mixed $dbName
     * @return bool
     * */
    public function dbClear($dbName){

        $dbNames = array();

        if(is_array($dbName)){
            foreach ($dbName as $db) {
                $dbNames[] = $db;
            }
        } else {
            $dbNames[] = $dbName;
        }

        foreach ( $dbNames as $dbName ) {

            $this->dbConnect($dbName);
            foreach ($this->tableList($dbName) as $tblName){
                if(!$this->tableClear($tblName)){
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Clear table.
     *
     * @param mixed $tblName
     * @return bool
     */
    public function tableClear($tblName){

        $tblNames = array();

        if(is_array($tblName)){
            foreach ($tblName as $value) {
                $tblNames[] = $value;
            }
        } else {
            $tblNames[] = $tblName;
        }

        foreach ($tblNames as $tblName) {

            $sql = '';
            switch ($this->db['drive']) {
                case 'mysql':
                    $sql = 'TRUNCATE `'.$tblName.'`';
                break;
                case 'sqlite':
                    $sql = 'DELETE FROM `'.$tblName.'`';
                break;
            }
            
            try{
                if($this->conn->query($sql)){
                    return true;
                } else {
                    return false;
                }
            } catch (Exception $e){
                return false;
            }

        }
        return true;
    }

    /**
     * Clear column.
     *
     * @param string $tblName
     * @param mixed $column
     * @return bool
     */
    public function columnClear($tblName, $column=null){

        if(empty($column)){
            return false;
        }

        $columns = array();

        if(is_array($column)){
            foreach ($column as $col) {
                $columns[] = $col;
            }
        } else {
            $columns[] = $column;
        }

        $columns = array_intersect($columns, $this->columnList($tblName));

        foreach ($columns as $column) {

            $id   = $this->increments($tblName);
            $data = $this->getData($tblName);

            foreach ($data as $row) {
                $values = array(
                    $column => ''
                );
                $this->update($tblName, $values, $row[$id]);
            }
        }

        return true;

    }

    /**
     * Add new record.
     *
     * @param string $tblName
     * @param array $values
     * @return bool
     */
    public function insert($tblName, $values, $trigger=null){
                    
        if(!is_null($values)){
            if (is_numeric(array_keys($values)[0])) {
                $values = array_values($values);
            } else {
                $values = [$values];
            }
        }
        
        if(!is_null($trigger)){
            if (is_numeric(array_keys($trigger)[0])) {
                $trigger = array_values($trigger);
            } else {
                $trigger = [$trigger];
            }
        }
        
        try {
            $this->conn->beginTransaction();
            foreach ($values as $rows) {
                $sql = '';
                $columns = [];
                $values = [];
                $sql .= 'INSERT INTO `'.$tblName.'` ';
                foreach (array_keys($rows) as $col) {
                    $columns[] = $col;
                    $values[] = '?';
                }
                $sql .= '('.implode(', ', $columns).')';
                $sql .= ' VALUES ('.implode(', ', $values).')';
                $this->conn->prepare($sql)->execute(array_values($rows));
            }
            if(!is_null($trigger)){
                foreach ($trigger as $row) {
                    foreach ($row as $table => $data) {
                        $sql = '';
                        $columns = [];
                        $values = [];
                        $sql .= 'INSERT INTO `'.$table.'` ';
                        foreach (array_keys($data) as $col) {
                            $columns[] = $col;
                            $values[] = '?';
                        }
                        $sql .= '('.implode(', ', $columns).')';
                        $sql .= ' VALUES ('.implode(', ', $values).')';
                        $this->conn->prepare($sql)->execute(array_values($data));
                    }
                }
            }

            $this->conn->commit();

            return true;

        } catch (Exception $e) {
            $this->conn->rollback();
            echo $e->getMessage();
        }
        return false;
    }

    /**
     * Record update.
     *
     * @param string $tblName
     * @param array $values
     * @param string $needle
     * @param mixed $column
     * @return bool
     */
    public function update($tblName, $values, $needle, $column=null){

        if(empty($column)){

            $column = $this->increments($tblName);

            if(empty($column)){
                return false;
            }

        }

        $xColumns = array_keys($values);

        $columns = $this->columnList($tblName);

        $prepareArray = array();
        foreach ( $xColumns as $col ) {

            if(!in_array($col, $columns)){
                return false;
            }

            $prepareArray[] = $col.'=?';
        }

        $values[$column] = $needle;

        $values = array_values($values);

        $sql = implode(',', $prepareArray);
        $sql .= ' WHERE '.$column.'=?';
        try{
            $this->conn->beginTransaction();
            $query = $this->conn->prepare("UPDATE".' `'.$tblName.'` SET '.$sql);
            $query->execute($values);
            $this->conn->commit();
            return true;
        }catch (Exception $e){
            $this->conn->rollback();
            echo $e->getMessage();
        }
        return false;
    }

    /**
     * Record delete.
     *
     * @param string $tblName
     * @param mixed $needle
     * @param mixed $column
     * @return bool
     */
    public function delete($tblName, $needle, $column=null, $trigger=null, $force=null){

        $status = false;

        // status
        if(is_bool($column)){
            $status = $column;
            $column = $this->increments($tblName);
            if(empty($column)) return false;
        }

        if(empty($column)){

            $column = $this->increments($tblName);
            if(empty($column)) return false;

        }

        if(is_bool($trigger) AND is_array($column)){ 
            $status = $trigger; 
            $trigger = $column;
            $column = $this->increments($tblName);
            if(empty($column)) return false;
        }

        if(is_bool($trigger) AND is_string($column)){ 
            $status = $trigger; 
        }

        if(is_null($trigger) AND is_array($column)){
            $trigger = $column;
            $column = $this->increments($tblName);
            if(empty($column)) return false;
        }

        if(is_bool($force)){
            $status = $force;
        }

        if(!is_array($needle)){
            $needle = array($needle);
        }

        $sql = 'WHERE '.$column.'=?';
        try{
            $this->conn->beginTransaction();

            if(!$status){
                foreach ($needle as $value) {
                    if(!$this->do_have($tblName, $value, $column)){
                        return false;
                    }
                }
            }

            if(is_null($trigger)){
                foreach ($needle as $value) {
                    $query = $this->conn->prepare("DELETE FROM".' `'.$tblName.'` '.$sql);
                    $query->execute(array($value));
                }
            }
            
            if(!is_null($trigger)){
                foreach ($needle as $value) {
                    $sql = 'WHERE '.$column.'=?';
                    $query = $this->conn->prepare("DELETE FROM".' `'.$tblName.'` '.$sql);
                    $query->execute(array($value));

                    if(is_array($trigger)){

                        foreach ($trigger as $table => $col) {
                            $sql = 'WHERE '.$col.'=?';
                            $query = $this->conn->prepare("DELETE FROM".' `'.$table.'` '.$sql);
                            $query->execute(array($value));
                        }

                    }
                }
                
            }

            $this->conn->commit();
            return true;
        }catch (Exception $e){
            $this->conn->rollBack();
            return false;
        }
    }

    /**
     * Record reading.
     *
     * @param string $tblName
     * @param array $options
     * @return array
     */
    public function getData($tblName, $options=null){

        $sql = '';
        $andSql = '';
        $orSql = '';
        $keywordSql = '';

        $columns = $this->columnList($tblName);

        if(!empty($options['column'])){

            if(!is_array($options['column'])){
                $options['column']= array($options['column']);
            }

            $options['column'] = array_intersect($options['column'], $columns);
            $columns = array_values($options['column']);
        } 
        $sqlColumns = $tblName.'.'.implode(', '.$tblName.'.', $columns);

        $prefix = '';
        $suffix = ' = ?';
        if(!empty($options['search']['scope'])){
            $options['search']['scope'] = mb_strtoupper($options['search']['scope']);
            switch ($options['search']['scope']) {
                case 'LIKE':  $prefix = ''; $suffix = ' LIKE ?'; break;
            }
        }

        $prepareArray = array();
        $executeArray = array();

        if(isset($options['search']['keyword'])){

            if ( !is_array($options['search']['keyword']) ) {
                $keyword = array($options['search']['keyword']);
            } else {
                $keyword = $options['search']['keyword'];
            }

            $searchColumns = $columns;
            if(!empty($options['search']['column'])){

                if(!is_array($options['search']['column'])){
                    $searchColumns = array($options['search']['column']);
                } else {
                    $searchColumns = $options['search']['column'];
                }

                $searchColumns = array_intersect($searchColumns, $columns);
            }

            foreach ( $searchColumns as $column ) {

                foreach ( $keyword as $value ) {
                    $prepareArray[] = $prefix.$tblName.'.'.$column.$suffix;
                    $executeArray[] = $value;
                }

            }

            $keywordSql .= '('.implode(' OR ', $prepareArray).')';

        }

        $delimiterArray = array('and', 'AND', 'or', 'OR');
        
        if(!empty($options['search']['delimiter']['and'])){
            if(in_array($options['search']['delimiter']['and'], $delimiterArray)){
                $options['search']['delimiter']['and'] = mb_strtoupper($options['search']['delimiter']['and']);
            } else {
                $options['search']['delimiter']['and'] = ' AND ';
            }
        } else {
            $options['search']['delimiter']['and'] = ' AND ';
        }

        if(!empty($options['search']['delimiter']['or'])){
            if(in_array($options['search']['delimiter']['or'], $delimiterArray)){
                $options['search']['delimiter']['or'] = mb_strtoupper($options['search']['delimiter']['or']);
            } else {
                $options['search']['delimiter']['or'] = ' OR ';
            }
        } else {
            $options['search']['delimiter']['or'] = ' OR ';
        }

        if(!empty($options['search']['or']) AND is_array($options['search']['or'])){

            if(!isset($options['search']['or'][0])){
                $options['search']['or'] = array($options['search']['or']);
            }

            foreach ($options['search']['or'] as $key => $row) {

                foreach ($row as $column => $value) {

                    $x[$key][] = (is_null($value)) ? $tblName.'.'.$column.' IS NULL' : $prefix.$column.$suffix;
                    if(!is_null($value)){
                        $prepareArray[] = $prefix.$tblName.'.'.$column.$suffix;
                        $executeArray[] = $value;
                    }
                }
                
                $orSql .= '('.implode(' OR ', $x[$key]).')';

                if(count($options['search']['or'])>$key+1){
                    $orSql .= ' '.$options['search']['delimiter']['or']. ' ';
                }
            }
        }
        
        if(!empty($options['search']['and']) AND is_array($options['search']['and'])){

            if(!isset($options['search']['and'][0])){
                $options['search']['and'] = array($options['search']['and']);
            }

            foreach ($options['search']['and'] as $key => $row) {

                foreach ($row as $column => $value) {

                    $x[$key][] = (is_null($value)) ? $tblName.'.'.$column.' IS NULL' : $prefix.$column.$suffix;
                    if(!is_null($value)){
                        $prepareArray[] = $prefix.$tblName.'.'.$column.$suffix;
                        $executeArray[] = $value;
                    }
                }
                
                $andSql .= '('.implode(' AND ', $x[$key]).')';

                if(count($options['search']['and'])>$key+1){
                    $andSql .= ' '.$options['search']['delimiter']['and']. ' ';
                }
            }

        }

        $delimiter = ' AND ';
        $sqlBox = array();

        if(!empty($keywordSql)){
            $sqlBox[] = $keywordSql;
        }

        if(!empty($andSql) AND !empty($orSql)){
            $sqlBox[] = '('.$andSql.$delimiter.$orSql.')';
        } else {
            if(!empty($andSql)){
                $sqlBox[] = '('.$andSql.')';
            }
            if(!empty($orSql)){
                $sqlBox[] = '('.$orSql.')';
            }
        }

        $ignoredBox = array();
        
        if(isset($options['search']['ignored'])){
            if(isset($options['search']['ignored'][0])){
                foreach ($options['search']['ignored'] as $rows) {
                    $ign = [];
                    foreach ($rows as $key => $row) {
                        $ign[] =  (is_null($row)) ? $tblName.'.'.$key.' IS NULL' :$key.'=?';
                        if(!is_null($row)){
                            $executeArray[] = $row;
                        }
                    }  
                    $ignoredBox[] = '('.implode(' AND ', $ign).')';
                    
                }
            } else {
                $ign = [];
                foreach ($options['search']['ignored'] as $key => $row) {
                    $ign[] =  (is_null($row)) ? $tblName.'.'.$key.' IS NULL' : $key.'=?';
                    if(!is_null($row)){
                        $executeArray[] = $row;
                    }
                }
                $ignoredBox[] = implode(' AND ', $ign);
            }
        }

        if(
            !empty($options['search']['or']) OR
            !empty($options['search']['and']) OR
            !empty($options['search']['keyword'])
        ){
            $sql .= 'WHERE '.implode($delimiter, $sqlBox);            
        }
        
        $SP = (!empty($sql)) ? ' AND' : ' WHERE';
        
        $sql .= (!empty($ignoredBox)) ? $SP.' NOT ('.implode(' OR ', $ignoredBox).')' : '';

        $betweenSql = '';
        $SP = (!empty($sql)) ? ' AND' : ' WHERE';
        if(!empty($options['search']['between']) AND is_array($options['search']['between'])){           
            if(empty($options['search']['between']['column'])){
                $options['search']['between']['column'] = $this->increments($tblName);
            }
            if(!isset($options['search']['between']['params'][0])){
                $options['search']['between']['params'] = array($options['search']['between']['params']);
            }
            $betweenSql .= (!empty($sql)) ? ' AND ' : ' ';
            foreach ($options['search']['between']['params'] as $key => $row) {
                $betweenSqlBox[] = '('.$options['search']['between']['column'].' BETWEEN ? AND ?)';
                $executeArray[] = $row['min'];
                $executeArray[] = $row['max'];
            }

            if(!empty($betweenSqlBox)){                
                $sql .= $SP.' ('.implode(' OR ', $betweenSqlBox).')';
            }
   
        }

        if(!empty($options['join'])){            
            
            $joinTypes = ['INNER JOIN', 'LEFT JOIN', 'RIGHT JOIN', 'FULL OUTER JOIN'];
            if(in_array(mb_strtoupper($options['join']['name']), $joinTypes)){
                $options['join']['name'] = mb_strtoupper($options['join']['name']);
                $inner = [];
                $xinnerColumns = [];

                foreach ($options['join']['tables'] as $table_name => $schema) {
                    $sub_columns = $this->columnList($table_name);
                    if(empty($schema['fields'])){
                        $schema['fields'] = $sub_columns;
                    }
                    if(!is_array($schema['fields'])){
                        $schema['fields'] = array($schema['fields']);
                    }
                    $schema['fields'] = array_intersect($sub_columns, $schema['fields']);
    
                    foreach ($schema['fields'] as $field) {
                        $xinnerColumns[] = $table_name.'.'.$field.' AS '.$table_name.'_'.$field;
                    }
                }
                $sqlColumns .= ', '.implode(', ',$xinnerColumns);

                if($options['join']['name'] === 'FULL OUTER JOIN'){

                    foreach ($options['join']['tables'] as $table_name => $schema) {
                        $inner[] =  "LEFT JOIN ".$table_name." ON ".$tblName.".".$schema['primary']."=".$table_name.".".$schema['secondary'];
                    }
                    // add where
                    $inner[] = $sql;

                    $inner[] = 'UNION';
                    $inner[] = 'SELECT '.$sqlColumns.' FROM '.$tblName.' ';
                    foreach ($options['join']['tables'] as $table_name => $schema) {
                        $inner[] =  "RIGHT JOIN ".$table_name." ON ".$tblName.".".$schema['primary']."=".$table_name.".".$schema['secondary'];
                    }
                    // add where
                    $inner[] = $sql;
                    
                    $jonExecuteArray = $executeArray;
                    foreach ($executeArray as $value) {
                        $jonExecuteArray[] = $value;
                    }
                    $executeArray = $jonExecuteArray;
                } else {
                    foreach ($options['join']['tables'] as $table_name => $schema) {
                        $inner[] = $options['join']['name']." ".$table_name." ON ".$tblName.".".$schema['primary']."=".$table_name.".".$schema['secondary'];
                    }
                    $inner[] = $sql;
                }
            
                $sql = "\n\t".implode("\n\t", $inner);        
                $sql .= ' AND '.$tblName.".".$schema['primary'].' IS NULL';
            }

        }
        

        if(!empty($options['sort'])){

            list($columnName, $sort) = explode(':', $options['sort']);
            if(in_array($sort, array('asc', 'ASC', 'desc', 'DESC'))){
                $sql .= ' ORDER BY '.$columnName.' '.mb_strtoupper($sort);
            }

        }
        
        if(!empty($options['limit'])){

            if(!empty($options['limit']['start']) AND $options['limit']['start']>0){
                $start = $options['limit']['start'].',';
            } else {
                $start = '0,';
            }

            if(!empty($options['limit']['end']) AND $options['limit']['end']>0){
                $end = $options['limit']['end'];
            } else {
                $end     = $this->newId($tblName)-1;
            }

            $sql .= ' LIMIT '.$start.$end;

        }

        $result = array();
        
        $this->sql = 'SELECT '.$sqlColumns.' FROM '.$tblName.' '.$sql;
        // echo $this->sql;        
        try{

            $query = $this->conn->prepare($this->sql);
            // $this->print_pre($executeArray);
            $query->execute($executeArray);

            $result = $query->fetchAll(PDO::FETCH_ASSOC);

            if(isset($options['format'])){
                switch ($options['format']) {

                    case 'json':
                        $result = json_encode($result);
                        break;
                }
            }
            return $result;

        }catch (Exception $e){
            // $this->print_pre($e);
            return $result;
        }
        
    }

    /**
     * Research assistant.
     *
     * @param string $tblName
     * @param array $map
     * @param mixed $column
     * @param mixed $ignored
     * @return array
     */
    public function samantha($tblName, $map, $column=null, $ignored=null)
    {
        $output = array();
        $columns = array();

        $scheme['search']['and'] = $map;

        if(!is_null($ignored)) {
            $scheme['search']['ignored'] = $ignored;
        }

        // Sütun(lar) belirtilmişse
        if (!empty($column)) {

            // bir sütun belirtilmişse
            if(!is_array($column)){
                $columns = array($column);
            } else {
                $columns = $column;
            }

            // tablo sütunları elde ediliyor
            $getColumns = $this->columnList($tblName);

            // belirtilen sütun(lar) var mı bakılıyor
            foreach($columns as $column){

                // yoksa boş bir array geri döndürülüyor
                if(!in_array($column, $getColumns)){
                    return [];
                }

            }

            // izin verilen sütun(lar) belirtiliyor
            $scheme['column'] = $columns;
        }

        $output = $this->getData($tblName, $scheme);

        return $output;
    }

    /**
     * Research assistant.
     * It serves to obtain a array.
     * 
     * @param string $tblName
     * @param array $map
     * @param mixed $column
     * @param mixed $ignored
     * @return array
     * 
     */
    public function theodore($tblName, $map, $column=null, $ignored=null){
       
        $data = $this->samantha($tblName, $map, $column, $ignored);

        if(count($data)==1 AND isset($data[0])){
            $output = $data[0];
        } else {
            $output = [];
        }

        return $output;
    }

    /**
     * Research assistant.
     * Used to obtain an element of an array
     * 
     * @param string $tblName
     * @param array $map
     * @param string $column
     * @param mixed $ignored
     * @return string
     * 
     */
    public function amelia($tblName, $map, $column, $ignored=null){

        $output = '';

        $data = $this->samantha($tblName, $map, $column, $ignored);

        if(count($data)==1 AND isset($data[0])){
            $output = $data[0][$column];
        }

        return $output;
    }

     /**
     * matilda function
     *
     * @param string $table
     * @param string|array $keyword
     * @param array $points
     * @param string|array|null $columns
     * @param integer|null $start
     * @param integer|null $end
     * @param integer|null $sort
     * @param string|null $format
     * @param mixed $ignored
     * @return array
     */
    public function matilda($table, $keyword, $points=null, $columns=[], $ignored=null, $start=0, $end=0, $sort=null, $format=null){

        $points = (empty($points)) ? null : $points;
        $keyword = (isset($keyword)) ? $keyword : '';
        $start = (empty($start)) ? null : $start;
        $end = (empty($end)) ? null : $end;
        $sort = (!isset($sort)) ? null : $sort;
        $format = (!isset($format)) ? null : $format;

        $options = [
            'search'=>[
                'scope'=>'like',
                'and'=>$points,
                'delimiter'=>[
                    'and'=>'or'
                ]
            ]
        ];

        if(!empty($keyword)){
            $options['search']['keyword'] = $keyword;
        }

        if(!empty($columns)){
            $options['column'] = $columns;
        }

        if(isset($start) or isset($end)){
            $options['limit'] = [
                'start'=>$start,
                'end'=>$end
            ];
        }

        if(isset($sort)){
            $options['sort'] = $sort;
        }

        if(isset($format)){
            $options['format'] = $format;
        }

        if(is_null($points)) { 
            unset($options['search']['and']); 
            unset($options['search']['delimiter']); 
        }

        if(!empty($ignored)){
            $options['search']['ignored'] = $ignored;
        }
        
        if(is_null($start)) { unset($options['limit']['start']); }
        if(is_null($end)) { unset($options['limit']['end']); }
        if(is_null($sort)) { unset($options['sort']); }
        if(is_null($format)) { unset($options['format']); }

        return $this->getData($table, $options);
    }

    /**
     * Entity verification.
     *
     * @param string $tblName
     * @param mixed $value
     * @param mixed $column
     * @param mixed $ignored
     * @return bool
     */
    public function do_have($tblName, $value, $column=null, $ignored=null){

        if(!is_array($value)){
            $options['search']['keyword'] = $value;
            if(!empty($column)){  $options['search']['column'] = $column;  }
        } else {
            $options['search']['and'] = $value;
        }
        
        if(!empty($ignored)){
            $options['search']['ignored'] = $ignored;
        }

        $data = $this->getData($tblName, $options);
        
        if(!empty($data)){
            return true;
        }
        return false;
    }

    /**
     * Provides the number of the current record.
     * 
     * @param string $tblName
     * @param array $needle
     * @return int
     */
    public function getId($tblName, $needle){
        return $this->amelia($tblName, $needle, $this->increments($tblName));
    }
    /**
     * New id parameter.
     *
     * @param string $tblName
     * @return int
     */
    public function newId($tblName){

        $IDs = [];
        $length = 1;
        $needle = $this->increments($tblName);

        switch ($this->db['drive']) {
            case 'mysql':
                foreach ($this->getData($tblName, array('column'=>$needle)) as $row) {
                    if(!in_array($row[$needle], $IDs)){
                        $IDs[] = $row[$needle];
                    }
                }
            break;
            case 'sqlite':
                $getSqliteTable = $this->theodore('sqlite_sequence', array('name'=>$tblName));
                $IDs[] = $getSqliteTable['seq'];
            break;
            
        }
        
        if(!empty($IDs)){
            $length = max($IDs)+1;
        } else {
            $this->tableClear($tblName);
        }
        
        return $length;
        
    }

    /**
     * Auto increment column.
     *
     * @param string $tblName
     * @return string
     * */
    public function increments($tblName){

        $columns = '';
        
        try{
            
            switch ($this->db['drive']) {
                case 'mysql':
                    $query = $this->conn->query('SHOW COLUMNS FROM `' . $tblName. '`', PDO::FETCH_ASSOC);
                    foreach ( $query as $column ) { 
                        if($column['Extra'] == 'auto_increment'){ $columns = $column['Field']; } 
                    }
                break;
                case 'sqlite':
                    $statement = $this->conn->query("PRAGMA TABLE_INFO(`".$tblName."`)");
                    $row = $statement->fetchAll(PDO::FETCH_ASSOC); 
                    foreach ($row as $column) {
                        if((int) $column['pk'] === 1){ $columns = $column['name']; }
                    }   

                break;
            }
            
            return $columns;

        } catch (Exception $e){
            return $columns;
        }

    }

    /**
     * Table structure converter for Mind
     * 
     * @param string $tblName
     * @return array
     */
    public function tableInterpriter($tblName, $column = null){

        $result  = array();
        $columns = array();
        $columns = (!is_null($column) AND is_array($column)) ? $column : $columns; // array
        $columns = (!is_null($column) AND !is_array($column)) ? [$column] : $columns; // string
        
        try{

            switch ($this->db['drive']) {
                case 'mysql':
                    $sql  =  'SHOW COLUMNS FROM `' . $tblName . '`';
                break;
                case 'sqlite':
                    $sql  =  'PRAGMA TABLE_INFO(`'. $tblName . '`)';
                break;
            }

            $query = $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

            foreach ( $query as $row ) {
                switch ($this->db['drive']) {
                    case 'mysql':
                        if(strstr($row['Type'], '(')){
                            $row['Length'] = implode('', $this->get_contents('(',')', $row['Type']));
                            $row['Type']   = explode('(', $row['Type'])[0];
                        }
                        
                    break;
                    case 'sqlite':                      
                        
                        // Field
                        $row['Field'] = $row['name'];

                        // Type, Length
                        if(strstr($row['type'], '(')){
                            $row['Length'] = implode('', $this->get_contents('(',')', $row['type']));
                            $row['Type']   = mb_strtolower(explode('(', $row['type'])[0]);
                        } else { $row['Type'] = mb_strtolower($row['type']); }

                        if($row['Type'] == 'integer') { $row['Type'] = 'int';}

                        $row['Null'] = ($row['notnull']==0) ? 'YES' : 'NO';
                        $row['Key'] = ($row['pk']==1) ? 'PRI' : '';
                        $row['Default'] = $row['dflt_value'];
                        $row['Extra'] = ($row['pk'] == 1) ? 'auto_increment' : '';
                        // remove old column name
                        unset($row['cid'], $row['pk'], $row['name'], $row['type'], $row['dflt_value'], $row['notnull']);
                    break;
                }

                if(!in_array($row['Field'], $columns)){
                    $row['Length'] = (isset($row['Length'])) ? $row['Length'] : '';
                    switch ($row['Type']) {
                        case 'int':
                            if($row['Extra'] == 'auto_increment'){
                                if(isset($row['Length'])){
                                    $row = $row['Field'].':increments@'.$row['Length'];
                                } else {
                                    $row = $row['Field'].':increments';
                                }
                            } else {
                                $row = $row['Field'].':int@'.$row['Length'];
                            }
                            break;
                        case 'varchar':
                            $row = $row['Field'].':string@'.$row['Length'];
                            break;
                        case 'text':
                            $row = $row['Field'].':small';
                            break;
                        case 'mediumtext':
                            $row = $row['Field'].':medium';
                            break;
                        case 'longtext':
                            $row = $row['Field'].':large';
                            break;
                        case 'decimal':
                            $row = $row['Field'].':decimal@'.$row['Length'];
                            break;
                    }
                    $result[] = $row;
                }
                
            }

            return $result;

        } catch (Exception $e){
            return $result;
        }
    }

    /**
     * Database backup method
     * 
     * @param string|array $dbnames
     * @param string $directory
     * @return json|export
     */
    public function backup($dbnames, $directory='')
    {
        $result = array();

        if(is_string($dbnames)){
            $dbnames = array($dbnames);
        }

        foreach ($dbnames as $dbname) {
            
            // database select
            $this->selectDB($dbname);
            // tabular data is obtained
            foreach ($this->tableList() as $table) {
                if($table != 'sqlite_sequence') {  // If it is not the table added by default to the sqlite database.
                    $incrementColumn = $this->increments($table);
                    if(!empty($incrementColumn)){
                        $increments = array(
                            'auto_increment'=>array(
                                'length'=>$this->newId($table)
                            )
                        );
                    }

                    $result[$dbname][$table]['config'] = $increments;
                    $result[$dbname][$table]['schema'] = $this->tableInterpriter($table);
                    $result[$dbname][$table]['data'] = $this->getData($table);
                }
            }

            
            
        }
        
        $data = $this->json_encode($result);
        $filename = $this->db['drive'].'_backup_'.$this->permalink($this->timestamp, array('delimiter'=>'_')).'.json';
        if(!empty($directory)){
            if(is_dir($directory)){
                $this->write($data, $directory.'/'.$filename);
            } 
        } else {
           $this->saveAs($data, $filename);
        }
        return $result;
        
    }

    /**
     * Method of restoring database backup
     * 
     * @param string|array $paths
     * @return array
     */
    public function restore($paths){

        $result = array();
        
        if(is_string($paths)){
            $paths = array($paths);
        }

        foreach ($paths as $path) {
            if(file_exists($path)){
                foreach (json_decode(file_get_contents($path), true) as $dbname => $rows) {
                    foreach ($rows as $tblName => $row) {

                        $this->dbConnect(['db'=>['dbname'=>$dbname]]);
                        $this->tableCreate($tblName, $row['schema']);

                        switch ($this->db['drive']) {
                            case 'mysql':
                                if(!empty($row['config']['auto_increment']['length'])){
                                    $length = $row['config']['auto_increment']['length'];
                                    $sql = "ALTER TABLE `".$tblName."` AUTO_INCREMENT = ".$length;
                                    $this->conn->query($sql);
                                }
                            break;
                        }
                        
                        if(!empty($row['data']) AND empty($this->getData($tblName))){
                            $this->insert($tblName, $row['data']);
                        }
                        $result[$dbname][$tblName] = $row;
                    }
                    
                }
            }
        }

        return $result;
    }

    /**
     * Paging method
     * 
     * @param string $tblName
     * @param array $options
     * @return json|array
     */
    public function pagination($tblName, $options=array()){

        $result = array();
        
        /* -------------------------------------------------------------------------- */
        /*                                   FORMAT                                   */
        /* -------------------------------------------------------------------------- */

        if(!isset($options['format'])){
            $format = '';
        } else {
            $format = $options['format'];
            unset($options['format']);
        }

        /* -------------------------------------------------------------------------- */
        /*                                    SORT                                    */
        /* -------------------------------------------------------------------------- */
        if(!isset($options['sort'])){
            $options['sort'] = '';
        } 

        /* -------------------------------------------------------------------------- */
        /*                                    LIMIT                                   */
        /* -------------------------------------------------------------------------- */
        $limit = 25;
        if(empty($options['limit'])){
            $options['limit'] = $limit;
        } else {
             if(!is_numeric($options['limit'])){
                $options['limit'] = $limit;
             }
        }
        $end = $options['limit'];
        unset($options['limit']);

        /* -------------------------------------------------------------------------- */
        /*                                 NAVIGATION                                 */
        /* -------------------------------------------------------------------------- */

        // Route path
        $route_path = (empty($options['navigation']['route_path'])) ? 'page' : $options['navigation']['route_path'];
        $prev = (empty($options['navigation']['prev'])) ? 'Prev' : $options['navigation']['prev'];
        $next = (empty($options['navigation']['next'])) ? 'Next' : $options['navigation']['next'];
        


        /* -------------------------------------------------------------------------- */
        /*                                    PAGE                                    */
        /* -------------------------------------------------------------------------- */

        $page = 1;
        $prefix = 'p';
        if(!empty($options['prefix'])){
            if(!is_numeric($options['prefix'])){
                $prefix = $options['prefix'];
            }
        }
        
        if(empty($this->post[$prefix])){
            $this->post[$prefix] = $page;
        } else {
            if(is_numeric($this->post[$prefix])){
                $page = $this->post[$prefix];
            } else {
                $this->post[$prefix] = $page;
            }
        }


        /* -------------------------------------------------------------------------- */
        /*                                   COLUMN                                   */
        /* -------------------------------------------------------------------------- */

        if(!isset($options['column']) OR empty($options['column'])){
            $options['column'] = array();
        }

        /* -------------------------------------------------------------------------- */
        /*                                   SEARCH                                   */
        /* -------------------------------------------------------------------------- */

        if(!isset($options['search']) OR empty($options['search'])){
            $options['search'] = array();
        }

        if(!is_array($options['search'])){
            $options['search'] = array();
        }


        /* -------------------------------------------------------------------------- */
        /*            Finding the total number of pages and starting points           */
        /* -------------------------------------------------------------------------- */
        $data = $this->getData($tblName, $options);
        $totalRow = count($data);
        $totalPage = ceil($totalRow/$end);
        $start = ($page*$end)-$end;


        /* -------------------------------------------------------------------------- */
        /*                          Generate navigation code                          */
        /* -------------------------------------------------------------------------- */
        $paged          = $page;
        $current_page   = $page;
        $max_num_pages  = $totalPage;
        
        $navigation = '<div class="pagination">';
        if($paged != 1){
            $navigation .= '<a class="prev" href="'.$route_path.'/'.($current_page - 1).'">'.$prev.'</a>';
            if ($current_page > 2){
                $navigation .= '<a class="page" href="'.$route_path.'/1">1</a>';
            }
            if ($current_page > 3){
                $navigation .= '<span class="dots">...</span>';
            }
        }
        
        if($current_page - 1 > 0){
            $navigation .= '<a class="page" href="'.$route_path.'/'.($current_page - 1).'">'.($current_page - 1).'</a>';
        }
        $navigation .='<span class="page_selected">'.$current_page.'</span>';
        if ($current_page + 1 < $max_num_pages){
            $navigation .='<a class="page" href="'.$route_path.'/'.($current_page + 1).'">'.($current_page + 1).'</a>';
        }
        if($current_page < $max_num_pages){
            if ($current_page < $max_num_pages - 2){
                $navigation .= '<span class="dots">...</span>';
            }
            $navigation .='<a class="page" href="'.$route_path.'/'.$max_num_pages.'">'.$max_num_pages.'</a>';
            $navigation .='<a class="next" href="'.$route_path.'/'.($current_page + 1).'">'.$next.'</a>';
        }
        $navigation .='</div>';


        $result = array(
            'data'          =>  array_slice($data, $start, $end), 
            'route_path'    =>  $route_path,
            'prefix'        =>  $prefix,
            'limit'         =>  $end,
            'totalPage'     =>  $totalPage,
            'totalRecord'   =>  $totalRow,
            'navigation'    =>  $navigation,
            'page'          =>  $page
        );

        switch ($format) {
            case 'json':
                return $this->json_encode($result); 
            break;
        }
        return $result;
    }

    /**
     * Translate
     * 
     * @param string $needle
     * @param string|null $lang
     * @return string
     */
    public function translate($needle, $lang=''){
        if(!in_array($lang, array_keys($this->languages()))){
            $lang = $this->lang['lang'];
        }

        $params = array(
            $this->lang['column']=>$lang, 
            $this->lang['haystack']=>$needle
        );
        return $this->amelia($this->lang['table'], $params, $this->lang['return']);
    }

    /**
     * Database verification.
     *
     * @param string $dbname
     * @return bool
     * */
    public function is_db($dbname){

        switch ($this->db['drive']) {
            case 'mysql':
                $sql     = 'SHOW DATABASES';

                try{
                    $query = $this->conn->query($sql, PDO::FETCH_ASSOC);

                    $dbnames = array();

                    if ( $query->rowCount() ){
                        foreach ( $query as $item ) {
                            $dbnames[] = $item['Database'];
                        }
                    }

                    return in_array($dbname, $dbnames) ? true : false;

                } catch (Exception $e){
                    return false;
                }
            break;
            case 'sqlite':
                return (isset($dbname) AND file_exists($dbname)) ? true : false;
            break;
        }

        return false;

    }

    /**
     * Table verification.
     *
     * @param string $tblName
     * @return bool
     */
    public function is_table($tblName){

        $sql = '';

        switch ($this->db['drive']) {
            case 'mysql':
                $sql = 'DESCRIBE `'.$tblName.'`';
            break;
            case 'sqlite':
                $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name='".$tblName."';";
            break;
        }
        
        try{
            return $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e){
            return false;
        }

    }

    /**
     * Column verification.
     *
     * @param string $tblName
     * @param string $column
     * @return bool
     * */
    public function is_column($tblName, $column){

        $columns = $this->columnList($tblName);

        if(in_array($column, $columns)){
            return true;
        } else {
            return false;
        }        
    }

    /**
     * Phone verification.
     *
     * @param string $str
     * @return bool
     * */
    public function is_phone($str){

        return preg_match('/^\(?\+?([0-9]{1,4})\)?[-\. ]?(\d{3})[-\. ]?([0-9]{7})$/', implode('', explode(' ', $str))) ? true : false;

    }

    /**
     * Date verification.
     *
     * @param string $date
     * @param string $format
     * @return bool
     * */
    public function is_date($date, $format = 'Y-m-d H:i:s'){

        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) == $date;
    }

    /**
     * Mail verification.
     *
     * @param string $email
     * @return bool
     */
    public function is_email($email){

        if ( filter_var($email, FILTER_VALIDATE_EMAIL) ) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Type verification.
     *
     * @param string $fileName
     * @param mixed $type
     * @return bool
     */
    public function is_type($fileName, $type){

        if( !empty($type) AND !is_array($fileName) ){

            $exc = $this->info($fileName, 'extension');

            if(!is_array($type)){
                $type = array($type);
            }

            return in_array($exc, $type) ? true : false;
        }
        return false;
    }

    /**
     * Size verification.
     *
     * @param mixed $first_size
     * @param string $second_size
     * @return bool
     * */
    public function is_size($first_size, $second_size){

        if(is_array($first_size)){
            if(isset($first_size['size'])){
                $first_size = $first_size['size'];
            }
        }

        if(strstr($first_size, ' ')){
            $first_size = $this->encodeSize($first_size);
        }

        if(strstr($second_size, ' ')){
            $second_size = $this->encodeSize($second_size);
        }

        if($first_size >= $second_size){
            return true;
        }
        
        return false;
    }

    /**
     * Color verification.
     *
     * @param string  $color
     * @return bool
     * */
    public function is_color($color){

        $colorArray = $this->json_decode('["AliceBlue","AntiqueWhite","Aqua","Aquamarine","Azure","Beige","Bisque","Black","BlanchedAlmond","Blue","BlueViolet","Brown","BurlyWood","CadetBlue","Chartreuse","Chocolate","Coral","CornflowerBlue","Cornsilk","Crimson","Cyan","DarkBlue","DarkCyan","DarkGoldenRod","DarkGray","DarkGrey","DarkGreen","DarkKhaki","DarkMagenta","DarkOliveGreen","DarkOrange","DarkOrchid","DarkRed","DarkSalmon","DarkSeaGreen","DarkSlateBlue","DarkSlateGray","DarkSlateGrey","DarkTurquoise","DarkViolet","DeepPink","DeepSkyBlue","DimGray","DimGrey","DodgerBlue","FireBrick","FloralWhite","ForestGreen","Fuchsia","Gainsboro","GhostWhite","Gold","GoldenRod","Gray","Grey","Green","GreenYellow","HoneyDew","HotPink","IndianRed ","Indigo ","Ivory","Khaki","Lavender","LavenderBlush","LawnGreen","LemonChiffon","LightBlue","LightCoral","LightCyan","LightGoldenRodYellow","LightGray","LightGrey","LightGreen","LightPink","LightSalmon","LightSeaGreen","LightSkyBlue","LightSlateGray","LightSlateGrey","LightSteelBlue","LightYellow","Lime","LimeGreen","Linen","Magenta","Maroon","MediumAquaMarine","MediumBlue","MediumOrchid","MediumPurple","MediumSeaGreen","MediumSlateBlue","MediumSpringGreen","MediumTurquoise","MediumVioletRed","MidnightBlue","MintCream","MistyRose","Moccasin","NavajoWhite","Navy","OldLace","Olive","OliveDrab","Orange","OrangeRed","Orchid","PaleGoldenRod","PaleGreen","PaleTurquoise","PaleVioletRed","PapayaWhip","PeachPuff","Peru","Pink","Plum","PowderBlue","Purple","RebeccaPurple","Red","RosyBrown","RoyalBlue","SaddleBrown","Salmon","SandyBrown","SeaGreen","SeaShell","Sienna","Silver","SkyBlue","SlateBlue","SlateGray","SlateGrey","Snow","SpringGreen","SteelBlue","Tan","Teal","Thistle","Tomato","Turquoise","Violet","Wheat","White","WhiteSmoke","Yellow","YellowGreen"]', true);

        if(in_array($color, $colorArray)){
            return true;
        }

        if($color == 'transparent'){
            return true;
        }

        if(preg_match('/^#[a-f0-9]{6}$/i', mb_strtolower($color, 'utf-8'))){
            return true;
        }

        if(preg_match('/^rgb\((?:\s*\d+\s*,){2}\s*[\d]+\)$/', mb_strtolower($color, 'utf-8'))) {
            return true;
        }

        if(preg_match('/^rgba\((\s*\d+\s*,){3}[\d\.]+\)$/i', mb_strtolower($color, 'utf-8'))){
            return true;
        }

        if(preg_match('/^hsl\(\s*\d+\s*(\s*\,\s*\d+\%){2}\)$/i', mb_strtolower($color, 'utf-8'))){
            return true;
        }

        if(preg_match('/^hsla\(\s*\d+(\s*,\s*\d+\s*\%){2}\s*\,\s*[\d\.]+\)$/i', mb_strtolower($color, 'utf-8'))){
            return true;
        }

        return false;
    }

    /**
     * URL verification.
     *
     * @param string $url
     * @return bool
     */
    public function is_url($url=null){

        if(!is_string($url)){
            return false;
        }

        $temp_string = (!preg_match('#^(ht|f)tps?://#', $url)) // check if protocol not present
            ? 'http://' . $url // temporarily add one
            : $url; // use current

        if ( filter_var($temp_string, FILTER_VALIDATE_URL)) {
            return true;
        } else {
            return false;
        }

    }

    /**
     * HTTP checking.
     *
     * @param string $url
     * @return bool
     */
    public function is_http($url){
        if (substr($url, 0, 7) == "http://"){
            return true;
        } else {
            return false;
        }
    }

    /**
     * HTTPS checking.
     * @param string $url
     * @return bool
     */
    public function is_https($url){
        if (substr($url, 0, 8) == "https://"){
            return true;
        } else {
            return false;
        }
    }

    /**
     * Json control of a string
     *
     * @param string $scheme
     * @return bool
     */
    public function is_json($scheme){

        if(is_null($scheme) OR is_array($scheme)) {
            return false;
        }

        if($this->json_decode($scheme)){
            return true;
        }

        return false;
    }

    /**
     * is_age
     * @param string $date
     * @param string|int $age
     * @param string $type
     * @return bool
     * 
     */
    public function is_age($date, $age, $type='min'){
        
        $today = date("Y-m-d");
        $diff = date_diff(date_create($date), date_create($today));
    
        if($type === 'max'){
            if($age >= $diff->format('%y')){
                return true;
            }
        }
        if($type === 'min'){
            if($age <= $diff->format('%y')){
                return true;
            }
        }
        
        return false;
    }

    /**
     * International Bank Account Number verification
     *
     * @params string $iban
     * @param $iban
     * @return bool
     */
    public function is_iban($iban){
        // Normalize input (remove spaces and make upcase)
        $iban = strtoupper(str_replace(' ', '', $iban));

        if (preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{1,30}$/', $iban)) {
            $country = substr($iban, 0, 2);
            $check = intval(substr($iban, 2, 2));
            $account = substr($iban, 4);

            // To numeric representation
            $search = range('A','Z');
            foreach (range(10,35) as $tmp)
                $replace[]=strval($tmp);
            $numstr = str_replace($search, $replace, $account.$country.'00');

            // Calculate checksum
            $checksum = intval(substr($numstr, 0, 1));
            for ($pos = 1; $pos < strlen($numstr); $pos++) {
                $checksum *= 10;
                $checksum += intval(substr($numstr, $pos,1));
                $checksum %= 97;
            }

            return ((98-$checksum) == $check);
        } else
            return false;
    }

    /**
     * ipv4 verification
     *
     * @params string $ip
     * @return bool
     */
    public function is_ipv4($ip){
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * ipv6 verification
     *
     * @params string $ip
     * @return bool
     */
    public function is_ipv6($ip){
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Blood group verification
     *
     * @param $blood
     * @param string|null $donor
     * @return bool
     */
    public function is_blood($blood, $donor = null){

        $bloods = $this->blood_groups();

        $map = array_keys($bloods);

        //  hasta ve varsa donör parametreleri filtreden geçirilir
        $blood = str_replace(array('RH', ' '), '', mb_strtoupper($blood));
        if(!is_null($donor)) $donor = str_replace(array('RH', ' '), '', mb_strtoupper($donor));

        // Kan grubu kontrolü
        if(in_array($blood, $map) AND is_null($donor)){
            return true;
        }

        // Donör uyumu kontrolü
        if(in_array($blood, $map) AND in_array($donor, $bloods[$blood]) AND !is_null($donor)){
            return true;
        }

        return false;

    }

    /**
     *  Validates a given Latitude
     * @param float|int|string $latitude
     * @return bool
     */
    public function is_latitude($latitude) {
        $lat_pattern  = '/\A[+-]?(?:90(?:\.0+)?|(?:[0-8]?\d(?:\.\d+)?|90(?:\.0+)?))\z/';
    
        if (preg_match($lat_pattern, $latitude)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     *  Validates a given longitude
     * @param float|int|string $longitude
     * @return bool
     */
    public function is_longitude($longitude) {

        $long_pattern = '/\A[+-]?(?:180(?:\.0+)?|(?:1[0-7]\d|\d{1,2})(?:\.\d+)?)\z/';
    
        if (preg_match($long_pattern, $longitude)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Validates a given coordinate
     *
     * @param float|int|string $lat Latitude
     * @param float|int|string $long Longitude
     * @return bool `true` if the coordinate is valid, `false` if not
     */
    public function is_coordinate($lat, $long) {

        if ($this->is_latitude($lat) AND $this->is_longitude($long)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Distance verification
     */
    public function is_distance($point1, $point2, $options){

        $symbols = array('m', 'km', 'mi', 'ft', 'yd');

        // Option variable control
       if(empty($options)){
           return false;
       }

       if(!strstr($options, ':')){
           return false;
       }

       $options = explode(':', trim($options, ':'));

       if(count($options) != 2){
           return false;
       }

       list($range, $symbol) = $options;

       if(!in_array(mb_strtolower($symbol), $symbols)){
           return false;
       }

       // Points control
        if(empty($point1) OR empty($point2)){
            return false;
        }
        if(!is_array($point1) OR !is_array($point2)){
            return false;
        }

        if(count($point1) != 2 OR count($point2) != 2){
            return false;
        }

        if(isset($point1[0]) AND isset($point1[1]) AND isset($point2[0]) AND isset($point2[1])){
            $distance_range = $this->distanceMeter($point1[0], $point1[1], $point2[0], $point2[1], $symbol);
            if($distance_range <= $range){
                return true;
            }
        }

        return false;
    }

    /**
     * md5 hash checking method.
     * 
     * @param string $md5
     * @return bool
     */
    public function is_md5($md5 = ''){
        return strlen($md5) == 32 && ctype_xdigit($md5);
    }
    
    /**
     * Checks if a string is a Base64 encoded string.
     *
     * @param string $string
     * @return bool
     */
    public function is_base64($string) {
        // Empty string control
        if (empty($string)) {
            return false;
        }
    
        // Character Control Apart from Base64 Character Set
        if (!preg_match('/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', $string)) {
            return false;
        }
    
        // Check if the length is 4 times
        if (strlen($string) % 4 !== 0) {
            return false;
        }
    
        // Dolgu karakterlerinin doğru yerde olup olmadığını kontrol edin
        $padding = substr_count($string, '=');
        if ($padding > 2 || ($padding > 0 && substr($string, -$padding) !== str_repeat('=', $padding))) {
            return false;
        }        
    
        // Base64 Decode process
        $decoded = base64_decode($string, true);
        if ($decoded === false) {
            return false;
        }
    
        // Control whether the Encode form matches the original string
        if (base64_encode($decoded) != $string) {
            return false;
        }        
        if (preg_match('/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', $decoded)) {            
            return true;
        } 
        if($this->contains_invalid_chars($decoded)){
            return false;
        }
        
        return true;
    }
    
    /**
     * Checks if the given string contains invalid (non-UTF-8) characters.
     *
     * This function uses the `mb_check_encoding` function to determine if the
     * provided string is compatible with UTF-8 encoding. If the string is not valid UTF-8,
     * it returns `true` indicating the presence of broken characters. Otherwise, it returns `false`.
     *
     * @param string $string The string to check for invalid characters.
     * 
     * @return bool Returns `true` if the string contains invalid UTF-8 characters, `false` otherwise.
     */
    public function contains_invalid_chars($string) {
        // Check if the data is compatible with UTF-8
        if (!mb_check_encoding($string, 'UTF-8')) {
            return true; // There are broken characters
        }
        return false; // No broken character
    }

    
    /**
	 * Determines if SSL is used.	 
	 * @return bool True if SSL, otherwise false.
	 */
    public function is_ssl() {
        if ( isset( $_SERVER['HTTPS'] ) ) {
            if ( 'on' === strtolower( $_SERVER['HTTPS'] ) ) {
                return true;
            }
     
            if ( '1' == $_SERVER['HTTPS'] ) {
                return true;
            }
        } elseif ( isset( $_SERVER['SERVER_PORT'] ) && ( '443' == $_SERVER['SERVER_PORT'] ) ) {
            return true;
        }
        return false;
    }

    /**
     * html special characters control
     * @param $code
     * @return bool
     */
    public function is_htmlspecialchars($code){
        if(strpos($code, '&lt;') OR strpos($code, '&gt;') OR strpos($code, '&quot;') OR strpos($code, '&#39;') OR strpos($code, '&amp;')){
            return true;    
        }
        return false;
    }

    /**
     * Morse code verification
     * @param string $morse
     * @return bool
     */
    public function is_morse($morse){

        $data = $this->morse_decode($morse);
        if(strstr($data, '#')){
            return false;
        }
        return true;
    }

    /**
     * Checks if a given string is a valid hexadecimal string.
     *
     * @param string $hex The string to check.
     * @return bool True if the string is a valid hexadecimal string, false otherwise.
     */
    public function is_hex($hex) {
        return preg_match('/^[0-9a-fA-F]+$/', $hex);
    }



    /**
     * Binary code verification
     * @param string|int $binary
     * @return bool
     */
    public function is_binary($binary) {
        if (preg_match('~^[01]+$~', str_replace(' ', '', $binary))) {
            return true;
        } 
        return false;
    }

    /**
     * Timecode verification
     * @param string
     * @return bool
     */
    public function is_timecode($timecode){
        if(preg_match('/^([00-59]{2}):([00-59]{2}):([00-59]{2})?$/', $timecode)){
            return true;
        }
        return false;
    }

    /**
     * Browser verification
     * @param string|null $agent
     * @param string|array $browser
     * @return bool
     */
    public function is_browser($browserName=null, $browsers=null){

        $browser = $this->getBrowser($browserName);
        if($browser != 'Unknown')
        {
            if(is_null($browsers)){
                return true;
            } else {
                $browsers = (!is_array($browsers)) ? array($browsers) : $browsers;
                if(in_array($browser, $browsers)){
                    return true;
                } 
            }
        }
        return false;

    }

    /**
     * Decimal detection
     * @param string|int $decimal
     * @return bool
     */
    public function is_decimal($decimal){
        if(preg_match('/^\d*\.?\d*$/', $decimal)){
            return true;
        }
        return false;
    }

    /**
     * ISBN validate
     * @param string $isbn
     * @param string|int|null $type
     * @return bool
     */
    public function is_isbn($isbn, $type=null){

        $regex = '/\b(?:ISBN(?:: ?| ))?((?:97[89])?\d{9}[\dx])\b/i';

        if (preg_match($regex, str_replace('-', '', $isbn), $matches)) {
            
            if(in_array(mb_strlen($matches[1],'UTF-8'), [13,10]) and !isset($type)){
                return true;
            }

            if(in_array(mb_strlen($matches[1],'UTF-8'), [13,10]) and isset($type)){
                return ($type === mb_strlen($matches[1],'UTF-8'));
            }

        }
        return false; // No valid ISBN found
    }

    /**
     * is_slug function
     *
     * @param string $str
     * @return boolean
     */
    public function is_slug($str){
        return preg_match('/^[a-zA-Z0-9-]+$/', $str);
    }

    /**
     * timecodeCompare
     * @param string $duration
     * @param string $timecode
     * @return bool
     */
    public function timecodeCompare($duration, $timecode){
        if($this->toSeconds($duration) <= $this->toSeconds($timecode)){
            return true;
        }
        return false;
    }

    /**
     * Port number control
     * @param string|int $port
     * @return bool
     */
    public function is_port($port) {
        return is_numeric($port) && $port >= 1 && $port <= 65535;
    }

    /**
     * Controls the specified ip address and port
     * @param string $ip
     * @param mixed $port
     * @return bool
     */
    function is_port_open($ip, $port = null) {
        $ch = curl_init();
        $port = (!is_null($port) ? $port : 80);

        if(!$this->is_port($port)) {
            return false;
        }

        curl_setopt($ch, CURLOPT_URL, $ip);
        curl_setopt($ch, CURLOPT_PORT, $port);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);    
        $result = curl_exec($ch);
        $error = curl_error($ch);

        curl_close($ch);
        if (!empty($error)) {
            return false;
        }
        return true;
    }

    /**
     * Verifies whether the specified user agent or the $_SERVER['HTTP_USER_AGENT'] value represents a bot.
     *
     * @param string|null $userAgent The user agent to verify. If not provided, $_SERVER['HTTP_USER_AGENT'] will be used.
     * @return bool Returns true if the user agent represents a bot, false otherwise.
     */
    public function is_bot($userAgent = null) {
        $userAgent = (empty($userAgent)) ? $_SERVER['HTTP_USER_AGENT'] : $userAgent;
    
        foreach ($this->bots() as $bot) {
            if (stripos($userAgent, $bot) !== false) {
                return true;
            }
        }
    
        return false;
    }
    

    /**
     * Question the accessibility of the remote file
     * @param string $file
     * @return boo
     */
    public function fileExists($file){
        
        $ch = curl_init($file);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_exec($ch);
        $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($responseCode == 200) ? true : false;
    }

    /**
     * It is a version of the series of "stristr" methods that 
     * support the series
     * 
     * @param string|array $haystack
     * @param string|array $needle
     * @param bool $before_needle
     * 
     * @return bool|string // Returns the matched substring. If needle is not found, returns false.
     */
    public function stristr($haystack, $needle, $before_needle = false){
        $haystack = !is_array($haystack) ? [$haystack] : $haystack;
        $needle = !is_array($needle) ? [$needle] : $needle;
        foreach ($haystack as $key => $haystack_item) {
            foreach ($needle as $needle_item) {                
                if(stristr($haystack_item, $needle_item)){
                   return (!$before_needle) ? true : stristr($haystack_item, $needle_item, true);
                }
            }
        }
        return false;
    }
    
    /**
     * It is a version of the series of "strstr" methods that 
     * support the series
     * 
     * @param string|array $haystack
     * @param string|array $needle
     * @param bool $before_needle
     * 
     * @return bool|string // Returns the matched substring. If needle is not found, returns false.
     */
    public function strstr($haystack, $needle, $before_needle = false){
        $haystack = !is_array($haystack) ? [$haystack] : $haystack;
        $needle = !is_array($needle) ? [$needle] : $needle;
        foreach ($haystack as $key => $haystack_item) {
            foreach ($needle as $needle_item) {                
                if(strstr($haystack_item, $needle_item)){
                   return (!$before_needle) ? true : strstr($haystack_item, $needle_item, true);
                }
            }
        }
        return false;
    }

    /**
     * Validity of radio call sign
     * @param string $callsign
     * @return bool
     */
    public function is_callsign($callsign) {
        $pattern = '/^[A-Z0-9]{3,6}$/'; 
        return preg_match($pattern, $callsign);
    }

    /**
     * Checks if the given timezone is valid.
     *
     * This method checks whether a given timezone string is a valid timezone
     * identifier according to the list of identifiers provided by the
     * DateTimeZone class.
     *
     * @param string $timezone The timezone string to check.
     * @return bool Returns true if the timezone is valid, otherwise false.
     */
    public function is_timezone($timezone) {
        if (in_array($timezone, DateTimeZone::listIdentifiers())) {
            return true;
        } else {
            return false;
        }
    }

    
    /**
     * Validation
     * 
     * @param array $rule
     * @param array $data
     * @param array $message
     * @return bool
     */
    public function validate($rule, $data, $message = array()){
      
        $extra = '';
        $limit = '';
        $rules = array();

        foreach($rule as $name => $value){
            
            if(strstr($value, '|')){
                foreach(explode('|', trim($value, '|')) as $val){
                    $rules[$name][] = $val;
                }
            } else {
                $rules[$name][] = $value;
            }

        }

        foreach($rules as $column => $rule){
            foreach($rule as $name){

                if(strstr($name, ':')){
                    $ruleData = explode(':', trim($name, ':'));
                    if(count($ruleData) == 2){
                        list($name, $extra) = $ruleData;
                    }
                    if(count($ruleData) == 3 AND $ruleData[0] != 'knownunique'){
                        list($name, $extra, $limit) = $ruleData;
                    }
                    if($ruleData[0] == 'knownunique'){

                        $name = $ruleData[0];
                        $extra = $ruleData[1];

                        if(count($ruleData) == 3){
                            $knownuniqueColumn = $column;                        
                            $knownuniqueValue = $ruleData[2];
                        }

                        if(count($ruleData) > 3){
                            $knownuniqueColumn = $ruleData[2]; 
                            $knownuniqueValue = implode(':', array_slice($ruleData, ($this->is_column($ruleData[1], $ruleData[2]) ? 3 : 2)));
                        }

                    }

                    // farklı zaman damgaları kontrolüne müsaade edildi.
                    if(count($ruleData) > 2 AND strstr($name, ' ') AND $ruleData[0] != 'knownunique'){
                        $x = explode(' ', $name);
                        list($left, $right) = explode(' ', $name);
                        list($name, $date1) = explode(':', $left);
                        $extra = $date1.' '.$right;
                    }
                }

                $data[$column] = (isset($data[$column])) ? $data[$column] : '';

                // İlgili kuralın mesajı yoksa kural adı mesaj olarak belirtilir.
                if(empty($message[$column][$name])){
                    $message[$column][$name] = $name;
                }
                
                switch ($name) {
                    case 'min-num':
                        if(!is_numeric($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        } else {
                            if($data[$column]<$extra){
                                $this->errors[$column][$name] = $message[$column][$name];
                            }
                        }
                    break;
                    case 'max-num':
                        if(!is_numeric($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        } else {
                            if($data[$column]>$extra){
                                $this->errors[$column][$name] = $message[$column][$name];
                            }
                        }
                    break;
                    case 'min-char':
                        if(strlen($data[$column])<$extra){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                        break;
                    case 'max-char':
                        if(strlen($data[$column])>$extra){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                        break;
                    case 'email':
                        if(!$this->is_email($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    case 'required':
                        if(!isset($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        } else {
                            if($data[$column] === ''){
                                $this->errors[$column][$name] = $message[$column][$name];
                            }
                        }
                        
                    break;
                    case 'phone':
                        if(!$this->is_phone($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    case 'date':
                        if(empty($extra)){
                            $extra = 'Y-m-d';
                        }
                        if(!$this->is_date($data[$column], $extra)){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    case 'json':
                        if(!$this->is_json($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    case 'color':
                        if(!$this->is_color($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    case 'url':
                        if(!$this->is_url($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    case 'https':
                        if(!$this->is_https($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    case 'http':
                        if(!$this->is_http($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    case 'numeric':
                        if(!is_numeric($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    case 'min-age':
                        if(!$this->is_age($data[$column], $extra)){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    case 'max-age':
                        if(!$this->is_age($data[$column], $extra, 'max')){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    case 'unique':

                        if(!$this->is_table($extra)){
                            $this->errors[$column][$name][] = 'Table not found.';
                        }
                        
                        if(!$this->is_column($extra, $column)){
                            $this->errors[$column][$name][] = 'Column not found.';
                        }

                        if($this->do_have($extra, $data[$column], $column)){
                            $this->errors[$column][$name] = $message[$column][$name];
                        } 

                    break;
                    case 'available':
                        $availableColumn = $column;
                        if(isset($limit)){
                            $availableColumn = $limit;
                        }

                        if(!$this->is_table($extra)){
                            $this->errors[$column][$name][] = 'Table not found.';
                        }
                        $availableColumn = (empty($availableColumn)) ? $column : $availableColumn;                        
                        if(!$this->is_column($extra,$availableColumn)){
                            $this->errors[$column][$name][] = 'Column not found.';
                        }

                        if(!$this->do_have($extra, $data[$column],$availableColumn)){
                            $this->errors[$column][$name] = $message[$column][$name];
                        } 
                    break;
                    case 'knownunique':
                        if(!$this->is_table($extra)){
                            $this->errors[$column][$name][] = 'Table not found.';
                        }
                        
                        if(!$this->is_column($extra, $column) AND !isset($knownuniqueColumn)){
                            $this->errors[$column][$name][] = 'Column not found.';
                        }

                        if(!isset($knownuniqueColumn) AND !isset($knownuniqueValue) AND isset($limit)){
                            $knownuniqueColumn = $column;
                            $knownuniqueValue = $limit;
                        }

                        if(!isset($limit)){
                            $this->errors[$column][$name] = $message[$column][$name];
                        } else {

                            $item = $this->theodore($extra, array($knownuniqueColumn=>$knownuniqueValue));
                            if(isset($item[$column])){
                                if($data[$column] != $item[$column] AND $this->do_have($extra, array($column=>$data[$column]))){
                                    $this->errors[$column][$name] = $message[$column][$name];
                                }     
                            } else {
                                if($data[$column] != $knownuniqueValue AND $this->do_have($extra, array($column=>$data[$column]))){
                                    $this->errors[$column][$name] = $message[$column][$name];
                                }    
                            }

                        }

                    break;
                    case 'bool':
                        $acceptable = array(true, false, 'true', 'false', 0, 1, '0', '1');
                        $wrongTypeMessage = 'True, false, 0 or 1 must be specified.';

                        if(isset($extra)){

                            if($extra === ''){
                                unset($extra);
                            }
                            
                        }

                        if(isset($data[$column]) AND isset($extra)){
                            if(in_array($data[$column], $acceptable, true) AND in_array($extra, $acceptable, true)){
                                if($data[$column] === 'true' OR $data[$column] === '1' OR $data[$column] === 1){
                                    $data[$column] = true;
                                }
                                if($data[$column] === 'false' OR $data[$column] === '0' OR $data[$column] === 0){
                                    $data[$column] = false;
                                }
    
                                if($extra === 'true' OR $extra === '1' OR $extra === 1){
                                    $extra = true;
                                }
                                if($extra === 'false' OR $extra === '0' OR $extra === 0){
                                    $extra = false;
                                }
    
                                if($data[$column] !== $extra){
                                    $this->errors[$column][$name] = $message[$column][$name];
                                }
                                
                            } else {
                                $this->errors[$column][$name] = $message[$column][$name];
                            }
                        } 

                        if(isset($data[$column]) AND !isset($extra)){
                            if(!in_array($data[$column], $acceptable, true)){
                                $this->errors[$column][$name] = $message[$column][$name];
                            }
                        }

                        if(!isset($data[$column]) AND isset($extra)){
                            if(!in_array($extra, $acceptable, true)){
                                $this->errors[$column][$name] = $message[$column][$name];
                            }
                        }

                    break;
                    case 'iban':
                        if(!$this->is_iban($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    case 'ipv4':
                        if(!$this->is_ipv4($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    case 'ipv6':
                        if(!$this->is_ipv6($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    case 'blood':
                        if(!empty($extra)){
                            if(!$this->is_blood($data[$column], $extra)){
                                $this->errors[$column][$name] = $message[$column][$name];
                            }
                        } else {
                            if(!$this->is_blood($data[$column])){
                                $this->errors[$column][$name] = $message[$column][$name];
                            }
                        }
                    break;                           
                    case 'latitude':

                        if(!$this->is_latitude($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        } 

                    break;
                    case 'longitude':

                        if(!$this->is_longitude($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        } 

                    break;                    
                    case 'coordinate':
                        if(!strstr($data[$column], ',')){
                            $this->errors[$column][$name] = $message[$column][$name];
                        } else {

                            $coordinates = explode(',', $data[$column]);
                            if(count($coordinates)==2){

                                list($lat, $long) = $coordinates;

                                if(!$this->is_coordinate($lat, $long)){
                                    $this->errors[$column][$name] = $message[$column][$name];
                                }

                            } else {
                                $this->errors[$column][$name] = $message[$column][$name];
                            }

                        }

                    break;
                    case 'distance':
                        if(strstr($data[$column], '@')){
                            $coordinates = explode('@', $data[$column]);
                            if(count($coordinates) == 2){

                                list($p1, $p2) = $coordinates;
                                $point1 = explode(',', $p1);
                                $point2 = explode(',', $p2);

                                if(strstr($extra, ' ')){
                                    $options = str_replace(' ', ':', $extra);
                                    if(!$this->is_distance($point1, $point2, $options)){
                                        $this->errors[$column][$name] = $message[$column][$name];
                                    }
                                } else {
                                    $this->errors[$column][$name] = $message[$column][$name];
                                }
                            } else {
                                $this->errors[$column][$name] = $message[$column][$name];
                            }
                        } else {
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    case 'languages':
                        if(!in_array($data[$column], array_keys($this->languages()))){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    case 'morse':
                        if(!$this->is_morse($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    case 'binary':
                        if(!$this->is_binary($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    case 'timecode':
                        if(!$this->is_timecode($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    case 'currencies':
                        if(!in_array($data[$column], array_keys($this->currencies()))){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    case 'decimal':
                        if(!$this->is_decimal($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    case 'isbn':
                        if(!$this->is_isbn($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    case 'in':
                        if(!empty($extra)){
                            $extra = strpos($extra, ',') ? explode(',', $extra) : [$extra];
                            if(!in_array($data[$column], $extra)){
                                $this->errors[$column][$name] = $message[$column][$name];
                            }
                        } else {
                            $this->errors[$column][$name] = 'The haystack was not found.';
                        }

                    break;
                    case 'slug':
                        if(!$this->is_slug($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    case 'port':
                        if(!$this->is_port($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    case 'port_open':
                        $extra = ($extra == '') ? null : $extra;
                        if(!$this->is_port_open($data[$column], $extra)){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    case 'fileExists':
                        if(!$this->fileExists($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    case 'md5':
                        if(!$this->is_md5($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    case 'base64':
                        if(!$this->is_base64($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    case 'bot':
                        if(!$this->is_bot($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;        
                    case 'callsign':
                        if(!$this->is_callsign($data[$column])) {
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                        break;            
                    case 'timezone':
                        if(!$this->is_timezone($data[$column])) {
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                        break;
                    default:
                        $this->errors[$column][$name] = 'Invalid rule has been blocked.';
                    break;
                }
                $extra = '';
            }
        }
       
        if(empty($this->errors)){
            return true;
        } else {
            return false;
        }
    }

    /**
     * Server policy maker
     */
    public function policyMaker(){

        $filename = '';
        $public_content = '';

        switch ($this->getSoftware()) {
            case ('Apache' || 'LiteSpeed'):
                $public_content = implode("\n", array(
                    'RewriteEngine On',
                    'RewriteCond %{REQUEST_FILENAME} -s [OR]',
                    'RewriteCond %{REQUEST_FILENAME} -l [OR]',
                    'RewriteCond %{REQUEST_FILENAME} -d',
                    'RewriteRule ^.*$ - [NC,L]',
                    'RewriteRule ^.*$ index.php [NC,L]'
                ));
                $filename = '.htaccess';
            break;
            case 'Microsoft-IIS':
                $public_content = implode("\n", array(
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?>",
                "<configuration>",
                    "\t<system.webServer>",
                        "\t\t<rewrite>",
                        "\t\t\t<rules>",
                            "\t\t\t\t<rule name=\"Imported Rule 1\" stopProcessing=\"true\">",
                            "\t\t\t\t\t<match url=\"^(.*)$\" ignoreCase=\"false\" />",
                            "\t\t\t\t\t<conditions>",
                            "\t\t\t\t\t\t<add input=\"{REQUEST_FILENAME}\" matchType=\"IsFile\" ignoreCase=\"false\" negate=\"true\" />",
                            "\t\t\t\t\t\t<add input=\"{REQUEST_FILENAME}\" matchType=\"IsDirectory\" ignoreCase=\"false\" negate=\"true\" />",
                            "\t\t\t\t\t</conditions>",
                            "\t\t\t\t\t<action type=\"Rewrite\" url=\"index.php\" appendQueryString=\"true\" />",
                        "\t\t\t\t</rule>",
                        "\t\t\t</rules>",
                        "\t\t</rewrite>",
                   "\t</system.webServer>",
                '</configuration>'
            ));
            
            $filename = 'web.config';
            break;
            
        }

        if($this->getSoftware() != 'Nginx'){

            if(!file_exists($filename)){
                $this->write($public_content, $filename);
            }

        }
        
    }

    /**
     * Pretty Print
     * @param mixed $data
     * @return void
     */
    public function print_pre($data){
        
        if($this->is_json($data)){
            $data = $this->json_encode($this->json_decode($data));
        }
        
        echo '<pre>';
        print_r($data);
        echo '</pre>';
    }

    /**
     * Array sorting function
     * 
     * @param mixed $data
     * @param string $sort
     * @param string|int $column
     * @return array|json
     */
    public function arraySort($data, $sort='ASC', $key='')
    {
        $is_json = FALSE;
        if($this->is_json($data)){
            $is_json = TRUE;
            $data = $this->json_decode($data);
        }

        $sort_name = SORT_DESC;
        if('ASC' === mb_strtoupper($sort, 'utf8')) $sort_name = SORT_ASC;

        if(!empty($key)){
            $keys = array_column($data, $key);
        } else {
            $keys = array_keys($data);
            asort($data);
        }
        
        array_multisort($keys, $sort_name, SORT_STRING, $data);

        if($is_json === TRUE){
            $data = $this->json_encode($data);
        }

        return $data;

    }

    /**
     * Path information
     *
     * @param string $fileName
     * @param string $type
     * @return bool|string
     */
    public function info($fileName, $type){

        if(empty($fileName) AND isset($type)){
            return false;
        }

        $object = pathinfo($fileName);

        if($type == 'extension'){
            return strtolower($object[$type]);
        }

        if($type == 'dirname'){
            return $this->get_absolute_path($object[$type]);
        }
        
        return $object[$type];
    }

    /**
     * Request collector
     *
     * @return mixed
     */
    public function request(){

        $jsonPOST = file_get_contents("php://input");
        $jsonPOST = $this->is_json($jsonPOST) ? $this->json_decode($jsonPOST) : [];

        if(isset($_POST) OR isset($_GET) OR isset($_FILES) OR isset($jsonPOST)){

            foreach (array_merge($_POST, $_GET, $_FILES, $jsonPOST) as $name => $value) {
                
                if(is_array($value)){
                    foreach($value as $key => $all ){

                        if(is_array($all)){
                            foreach($all as $i => $val ){
                                $this->post[$name][$i][$key] = $this->filter($val);
                            }
                        } else {
                            $this->post[$name][$key] = $this->filter($all);
                        }
                    }
                } else {
                    $this->post[$name] = $this->filter($value);
                }
            }
        }

        return $this->post;
    }

    /**
     * Filter
     * 
     * @param string $str
     * @return string
     */
    public function filter($str){        
        return htmlspecialchars((!is_null($str)) ? $str : '');
    }

    /**
     * Firewall
     * 
     * @param array $conf
     * @return string header()
     */
    public function firewall($conf=array()){

        if(empty($conf['firewall']['allow']['folder'])){
            $conf['firewall']['allow']['folder'] = array('public');
        }

        if(empty($_SERVER['HTTP_USER_AGENT'])){
            $this->abort('400', 'User agent is required.');
        }

        $noiframe = "X-Frame-Options: SAMEORIGIN";
        $noxss = "X-XSS-Protection: 1; mode=block";
        $nosniff = "X-Content-Type-Options: nosniff";
        $ssl = "Set-Cookie: user=t=".$this->generateToken()."; path=/; Secure";
        $hsts = "Strict-Transport-Security: max-age=16070400; includeSubDomains; preload";

        $noiframe_status = (isset($conf['noiframe']) AND $conf['firewall']['noiframe'] == TRUE) ? TRUE : FALSE;
        $noxss_status = (isset($conf['firewall']['noxss']) AND $conf['firewall']['noxss'] == TRUE) ? TRUE : FALSE;
        $nosniff_status = (isset($conf['firewall']['nosniff']) AND $conf['firewall']['nosniff'] == TRUE) ? TRUE : FALSE;
        $ssl_status = (isset($conf['firewall']['ssl']) AND $conf['firewall']['ssl'] == TRUE) ? TRUE : FALSE;
        $hsts_status = (isset($conf['firewall']['hsts']) AND $conf['firewall']['hsts'] == TRUE) ? TRUE : FALSE;

        if($noiframe_status === TRUE){ header($noiframe); }
        if($noxss_status === TRUE){ header($noxss); }
        if($nosniff_status === TRUE){ header($nosniff); }
        if($ssl_status === TRUE){ header($ssl); }
        if($hsts_status === TRUE){ header($hsts); }

        if($ssl_status === TRUE AND $this->is_ssl() === FALSE){
            $this->abort('400', 'SSL is required.');
        }        
        if($hsts_status === TRUE AND ($this->is_ssl() OR $ssl_status == FALSE)){
            $this->abort('503', 'SSL is required for HSTS.');
        }
        
        $limit = 200;
        $name = 'csrf_token';
        $status = true;

        if(!empty($conf)){

            if(isset($conf['firewall']['csrf'])){
                if(!empty($conf['firewall']['csrf']['name'])){
                    $name = $conf['firewall']['csrf']['name'];
                }
                if(!empty($conf['firewall']['csrf']['limit'])){
                    $limit = $conf['firewall']['csrf']['limit'];
                }
                if(is_bool($conf['firewall']['csrf'])){
                    $status = $conf['firewall']['csrf'];
                }
            }            
        }

        if($status){

            if($_SERVER['REQUEST_METHOD'] === 'POST'){
                if(isset($this->post[$name]) AND isset($_SESSION['csrf']['token'])){
                    if($this->post[$name] == $_SESSION['csrf']['token']){
                        unset($this->post[$name]);
                    } else {
                        $this->abort('401', 'A valid token could not be found.');
                    }
                } else {
                    $this->abort('400', 'Token not found.');
                }
                
            } 

            if(!isset($_SESSION['csrf']) OR $_SERVER['REQUEST_METHOD'] === 'POST'){

                $_SESSION['csrf'] = array(
                    'name'  =>  $name,
                    'token' =>  $this->generateToken($limit)                    
                );
                $_SESSION['csrf']['input'] = "<input type=\"hidden\" name=\"".$_SESSION['csrf']['name']."\" value=\"".$_SESSION['csrf']['token']."\">";
            }

        } else {
            if(isset($_SESSION['csrf'])){
                unset($_SESSION['csrf']);
            }
        }

        if(
            $_SERVER['REQUEST_METHOD'] === 'POST' AND isset($this->post['captcha']) AND 
            isset($_SESSION['captcha']) AND ($_SESSION['captcha'] != $this->post['captcha']))
        {
            $this->errors['captcha']['required'] = 'Captcha validation failed.';
        }

        $conf['firewall']['allow']['platform'] = (isset($conf['firewall']['allow']['platform']) ? $conf['firewall']['allow']['platform'] : []);
        $conf['firewall']['allow']['browser'] = (isset($conf['firewall']['allow']['browser']) ? $conf['firewall']['allow']['browser'] : []);
        $conf['firewall']['allow']['ip'] = (isset($conf['firewall']['allow']['ip']) ? $conf['firewall']['allow']['ip'] : []);
        $conf['firewall']['allow']['folder'] = (isset($conf['firewall']['allow']['folder']) ? $conf['firewall']['allow']['folder'] : []);
        $conf['firewall']['deny']['platform'] = (isset($conf['firewall']['deny']['platform']) ? $conf['firewall']['deny']['platform'] : []);
        $conf['firewall']['deny']['browser'] = (isset($conf['firewall']['deny']['browser']) ? $conf['firewall']['deny']['browser'] : []);
        $conf['firewall']['deny']['ip'] = (isset($conf['firewall']['deny']['ip']) ? $conf['firewall']['deny']['ip'] : []);
        $conf['firewall']['deny']['folder'] = (isset($conf['firewall']['deny']['folder']) ? $conf['firewall']['deny']['folder'] : []);

        $conf['firewall']['allow']['platform'] = (!is_array($conf['firewall']['allow']['platform']) ? [$conf['firewall']['allow']['platform']] : $conf['firewall']['allow']['platform']);
        $conf['firewall']['allow']['browser'] = (!is_array($conf['firewall']['allow']['browser']) ? [$conf['firewall']['allow']['browser']] : $conf['firewall']['allow']['browser']);
        $conf['firewall']['allow']['ip'] = (!is_array($conf['firewall']['allow']['ip']) ? [$conf['firewall']['allow']['ip']] : $conf['firewall']['allow']['ip']);
        $conf['firewall']['allow']['folder'] = (!is_array($conf['firewall']['allow']['folder']) ? [$conf['firewall']['allow']['folder']] : $conf['firewall']['allow']['folder']);
        $conf['firewall']['deny']['platform'] = (!is_array($conf['firewall']['deny']['platform']) ? [$conf['firewall']['deny']['platform']] : $conf['firewall']['deny']['platform']);
        $conf['firewall']['deny']['browser'] = (!is_array($conf['firewall']['deny']['browser']) ? [$conf['firewall']['deny']['browser']] : $conf['firewall']['deny']['browser']);
        $conf['firewall']['deny']['ip'] = (!is_array($conf['firewall']['deny']['ip']) ? [$conf['firewall']['deny']['ip']] : $conf['firewall']['deny']['ip']);
        $conf['firewall']['deny']['folder'] = (!is_array($conf['firewall']['deny']['folder']) ? [$conf['firewall']['deny']['folder']] : $conf['firewall']['deny']['folder']);

        $platform = $this->getClientOS();
        if(
            !empty($conf['firewall']['deny']['platform']) AND
            in_array($platform, array_values($conf['firewall']['deny']['platform'])) OR
            !empty($conf['firewall']['allow']['platform']) AND
            !in_array($platform, array_values($conf['firewall']['allow']['platform']))
            ){
            $this->abort('401', 'Your operating system is not allowed.');
        }

        $browser = $this->getBrowser();
        if(
            !empty($conf['firewall']['deny']['browser']) AND
            in_array($browser, array_values($conf['firewall']['deny']['browser'])) OR
            !empty($conf['firewall']['allow']['browser']) AND 
            !in_array($browser, array_values($conf['firewall']['allow']['browser']))
            ){
            $this->abort('401', 'Your browser is not allowed.');
        }

        $ip = $this->getIPAddress();
        if(
            !empty($conf['firewall']['deny']['ip']) AND
            in_array($ip, array_values($conf['firewall']['deny']['ip'])) OR
            !empty($conf['firewall']['allow']['ip']) AND
            !in_array($ip, array_values($conf['firewall']['allow']['ip']))
            ){
            $this->abort('401', 'Your IP address is not allowed.');
        }

        $folders = array_filter(glob('*'), 'is_dir');
        $filename = '';
        $deny_content = '';
        $allow_content = '';
        switch ($this->getSoftware()) {
            case ('Apache' || 'LiteSpeed'):
                $deny_content = 'Deny from all';
                $allow_content = 'Allow from all';
                $filename = '.htaccess';
            break;
            case 'Microsoft-IIS':
                
                $deny_content = implode("\n", array(
                    "<authorization>",
                    "\t<deny users=\"?\"/>",
                    "</authorization>"
                ));
                $allow_content = implode("\n", array(
                    "<configuration>",
                    "\t<system.webServer>",
                    "\t\t<directoryBrowse enabled=\"true\" showFlags=\"Date,Time,Extension,Size\" />",
                    "\t\t\t</system.webServer>",
                    "</configuration>"
                ));
                $filename = 'web.config';
            break;
            
        }

        if($platform != 'Nginx'){
            if(!empty($folders)){
                foreach ($folders as $dir){
    
                    if(in_array($dir, $conf['firewall']['deny']['folder']) AND !file_exists($dir.'/'.$filename)){
                        $this->write($deny_content, $dir.'/'.$filename);
                    }
                    if(in_array($dir, $conf['firewall']['allow']['folder']) AND !file_exists($dir.'/'.$filename)){
                        $this->write($allow_content, $dir.'/'.$filename);
                    }
                    
                    if(!file_exists($dir.'/'.$filename)){
                        $this->write($deny_content, $dir.'/'.$filename);
                    }
                    if(!file_exists($dir.'/index.html')){
                        $this->write(' ', $dir.'/index.html');
                    }

                }
            }
        }


        if(isset($conf['firewall']['lifetime'])){

            
            // if only two are specified
            if(isset($conf['firewall']['lifetime']['start']) AND isset($conf['firewall']['lifetime']['end'])){
                if((!$this->lifetime($conf['firewall']['lifetime']['end'])) OR 
                (!$this->lifetime($conf['firewall']['lifetime']['start'], $conf['firewall']['lifetime']['end']))){
                        $message = (isset($conf['firewall']['lifetime']['message'])) ? $conf['firewall']['lifetime']['message'] : 'The access right granted to you has expired.';
                        $this->abort('401', $message);
                    }
                }
                
                // only if the start date is specified
                if(isset($conf['firewall']['lifetime']['start']) AND !isset($conf['firewall']['lifetime']['end'])){
                    if(!$this->lifetime($conf['firewall']['lifetime']['start'], $this->timestamp)){
                        $message = (isset($conf['firewall']['lifetime']['message'])) ? $conf['firewall']['lifetime']['message'] : 'You must wait for the specified time to use your access right.';
                    $this->abort('401', $message);
                }
            }

            // only if the end date is specified
            if(!isset($conf['firewall']['lifetime']['start']) AND isset($conf['firewall']['lifetime']['end'])){
                if(!$this->lifetime($conf['firewall']['lifetime']['end'])){
                    $message = (isset($conf['firewall']['lifetime']['message'])) ? $conf['firewall']['lifetime']['message'] : 'The deadline for your access has expired.';
                    $this->abort('401', $message);
                }
            }
        }

    }

    /**
     * Redirect
     *
     * @param string $url
     * @param int $delay,
     * @param string $element
     */
    public function redirect($url = '', $delay = 0, $element=''){

        if(!$this->is_http($url) AND !$this->is_https($url) OR empty($url)){
            $url = $this->base_url.$url;
        }

        if(0 !== $delay){
            if(!empty($element)){
        ?>
            <script>
                let wait = 1000,
                    delay = <?=$delay;?>,
                    element = "<?=$element;?>";

                setInterval(function () {
                    elements = document.querySelectorAll(element);
                    if(delay !== 0){
                        
                        if(elements.length >= 1){

                            elements.forEach(function(element) {
                                if(element.value === undefined){
                                    element.textContent = delay;
                                } else {
                                    element.value = delay;
                                }
                            });
                        }
                    }
                    delay--;
                }, wait);
            </script>
        <?php
                }
            header('refresh:'.$delay.'; url='.$url);
        } else {
            header('Location: '.$url);
        }
        ob_end_flush();
        if(empty($element)){
            exit();
        }
    }

    /**
     * Permanent connection.
     *
     * @param string $str
     * @param array $options
     * @return string
     */
    public function permalink($str, $options = array()){

        $str = htmlspecialchars_decode($str);
        $plainText = $str;
        $defaults = array(
            'delimiter' => '-',
            'limit' => null,
            'lowercase' => true,
            'replacements' => array(),
            'transliterate' => true,
            'unique' => array(
                'delimiter' => '-',
                'linkColumn' => 'link',
                'titleColumn' => 'title'
            )
        );

        $char_map = [

            // Latin
            'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A', 'Æ' => 'AE', 'Ç' => 'C',
            'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ð' => 'D', 'Ñ' => 'N', 'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ő' => 'O',
            'Ø' => 'O', 'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ű' => 'U', 'Ý' => 'Y', 'Þ' => 'TH',
            'ß' => 'ss',
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'ae', 'ç' => 'c',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ð' => 'd', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ő' => 'o',
            'ø' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ű' => 'u', 'ý' => 'y', 'þ' => 'th',
            'ÿ' => 'y',

            // Latin symbols
            '©' => '(c)',

            // Greek
            'Α' => 'A', 'Β' => 'B', 'Γ' => 'G', 'Δ' => 'D', 'Ε' => 'E', 'Ζ' => 'Z', 'Η' => 'H', 'Θ' => '8',
            'Ι' => 'I', 'Κ' => 'K', 'Λ' => 'L', 'Μ' => 'M', 'Ν' => 'N', 'Ξ' => '3', 'Ο' => 'O', 'Π' => 'P',
            'Ρ' => 'R', 'Σ' => 'S', 'Τ' => 'T', 'Υ' => 'Y', 'Φ' => 'F', 'Χ' => 'X', 'Ψ' => 'PS', 'Ω' => 'W',
            'Ά' => 'A', 'Έ' => 'E', 'Ί' => 'I', 'Ό' => 'O', 'Ύ' => 'Y', 'Ή' => 'H', 'Ώ' => 'W', 'Ϊ' => 'I',
            'Ϋ' => 'Y',
            'α' => 'a', 'β' => 'b', 'γ' => 'g', 'δ' => 'd', 'ε' => 'e', 'ζ' => 'z', 'η' => 'h', 'θ' => '8',
            'ι' => 'i', 'κ' => 'k', 'λ' => 'l', 'μ' => 'm', 'ν' => 'n', 'ξ' => '3', 'ο' => 'o', 'π' => 'p',
            'ρ' => 'r', 'σ' => 's', 'τ' => 't', 'υ' => 'y', 'φ' => 'f', 'χ' => 'x', 'ψ' => 'ps', 'ω' => 'w',
            'ά' => 'a', 'έ' => 'e', 'ί' => 'i', 'ό' => 'o', 'ύ' => 'y', 'ή' => 'h', 'ώ' => 'w', 'ς' => 's',
            'ϊ' => 'i', 'ΰ' => 'y', 'ϋ' => 'y', 'ΐ' => 'i',

            // Turkish
            'Ş' => 'S', 'İ' => 'I', 'Ğ' => 'G',
            'ş' => 's', 'ı' => 'i', 'ğ' => 'g',

            // Russian
            'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D', 'Е' => 'E', 'Ё' => 'Yo', 'Ж' => 'Zh',
            'З' => 'Z', 'И' => 'I', 'Й' => 'J', 'К' => 'K', 'Л' => 'L', 'М' => 'M', 'Н' => 'N', 'О' => 'O',
            'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T', 'У' => 'U', 'Ф' => 'F', 'Х' => 'H', 'Ц' => 'C',
            'Ч' => 'Ch', 'Ш' => 'Sh', 'Щ' => 'Sh', 'Ъ' => '', 'Ы' => 'Y', 'Ь' => '', 'Э' => 'E', 'Ю' => 'Yu',
            'Я' => 'Ya',
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'yo', 'ж' => 'zh',
            'з' => 'z', 'и' => 'i', 'й' => 'j', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o',
            'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c',
            'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sh', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu',
            'я' => 'ya',

            // Ukrainian
            'Є' => 'Ye', 'І' => 'I', 'Ї' => 'Yi', 'Ґ' => 'G',
            'є' => 'ye', 'і' => 'i', 'ї' => 'yi', 'ґ' => 'g',

            // Czech
            'Č' => 'C', 'Ď' => 'D', 'Ě' => 'E', 'Ň' => 'N', 'Ř' => 'R', 'Š' => 'S', 'Ť' => 'T', 'Ů' => 'U',
            'Ž' => 'Z',
            'č' => 'c', 'ď' => 'd', 'ě' => 'e', 'ň' => 'n', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ů' => 'u',
            'ž' => 'z',

            // Polish
            'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'e', 'Ł' => 'L', 'Ń' => 'N', 'Ś' => 'S', 'Ź' => 'Z',
            'Ż' => 'Z',
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ś' => 's', 'ź' => 'z',
            'ż' => 'z',

            // Latvian
            'Ā' => 'A', 'Ē' => 'E', 'Ģ' => 'G', 'Ī' => 'i', 'Ķ' => 'k', 'Ļ' => 'L', 'Ņ' => 'N', 'Ū' => 'u',
            'ā' => 'a', 'ē' => 'e', 'ģ' => 'g', 'ī' => 'i', 'ķ' => 'k', 'ļ' => 'l', 'ņ' => 'n', 'ū' => 'u',
        ];

        $replacements = array();

        if(!empty($options['replacements']) AND is_array($options['replacements'])){
            $replacements = $options['replacements'];
        }

        if(isset($options['transliterate']) AND !$options['transliterate']){
            $char_map = array();
        }

        $options['replacements'] = array_merge($replacements, $char_map);

        if(!empty($options['replacements']) AND is_array($options['replacements'])){
            foreach ($options['replacements'] as $objName => $val) {
                $str = str_replace($objName, $val, $str);

            }
        }

        $options = array_merge($defaults, $options);
        $str = preg_replace('/[^\p{L}\p{Nd}_]+/u', $options['delimiter'], $str);
        $str = preg_replace('/(' . preg_quote($options['delimiter'], '/') . '){2,}/', '$1', $str);
        $str = mb_substr($str, 0, ($options['limit'] ? $options['limit'] : mb_strlen($str, 'UTF-8')), 'UTF-8');
        $str = trim($str, $options['delimiter']);
        $link = $options['lowercase'] ? mb_strtolower($str, 'UTF-8') : $str;

        if(!empty($options['unique']['tableName'])){

            $tableName = $options['unique']['tableName'];
            $delimiter = $defaults['unique']['delimiter'];
            $titleColumn = $defaults['unique']['titleColumn'];
            $linkColumn = $defaults['unique']['linkColumn'];

            if(!$this->is_table($options['unique']['tableName'])){
                return $link;
            } else {

                if(!empty($options['unique']['delimiter'])){
                    $delimiter = $options['unique']['delimiter'];
                }
                if(!empty($options['unique']['titleColumn'])){
                    $titleColumn = $options['unique']['titleColumn'];
                }
                if(!empty($options['unique']['linkColumn'])){
                    $linkColumn = $options['unique']['linkColumn'];
                }

                $data = $this->samantha($tableName, array($titleColumn => $plainText));

                if(!empty($data)){
                    $num = count($data)+1;
                } else {
                    $num = 1;
                }

                for ($i = 1; $i<=$num; $i++){

                    if(!$this->do_have($tableName, $link, $linkColumn)){
                        return $link;
                    } else {
                        if(!$this->do_have($tableName, $link.$delimiter.$i, $linkColumn)){
                            return $link.$delimiter.$i;
                        }
                    }
                }
                return $link.$delimiter.$num;
            }
        }

        if(!empty($options['unique']['directory'])){
            $param = $options['delimiter'];
            $list = glob($options['unique']['directory'].$link."*");
            $totalFiles = count($list);

            if($totalFiles == 1){
                $link = $link.$options['delimiter'].'1';
            } else {
                if($totalFiles > 1){
                    $param .= count($list)+1;
                } 
                if($totalFiles == 0 ){
                    $param = '';
                }
                $link = $link.$param;
            }
            
        }

        return $link;
    }

    /**
     * timeForPeople
     * Indicates the elapsed time.
     * @param string $datetime
     * @param array|null $translations
     * @return string
     */
    public function timeForPeople($datetime, $translations=[]) {        

        $datetime = (is_null($datetime)) ? '' : $datetime;
        $now = new DateTime();
        $ago = new DateTime($datetime);
        $diff = $now->diff($ago);

        $translations['a'] = (isset($translations['a'])) ? $translations['a'] : 'ago';
        $translations['p'] = (isset($translations['p'])) ? $translations['p'] : 's';
        $translations['l'] = (isset($translations['l'])) ? $translations['l'] : 'later';
        $translations['j'] = (isset($translations['j'])) ? $translations['j'] : 'just now';
        $translations['f'] = (isset($translations['f'])) ? $translations['f'] : false;

        $string = array(
            'y' => (!isset($translations['y'])) ? 'year' : $translations['y'],
            'm' => (!isset($translations['m'])) ? 'month' : $translations['m'],
            'w' => (!isset($translations['w'])) ? 'week' : $translations['w'],
            'd' => (!isset($translations['d'])) ? 'day' : $translations['d'],
            'h' => (!isset($translations['h'])) ? 'hour' : $translations['h'],
            'i' => (!isset($translations['i'])) ? 'minute' : $translations['i'],
            's' => (!isset($translations['s'])) ? 'second' : $translations['s'],
        );

        foreach ($string as $key => $val) {

            if (isset($diff->$key)) {
                if($diff->$key>0){
                    $string[$key] = $diff->$key . ' ' . $val . ($diff->$key > 1 ? $translations['p'] : '');
                } else {
                    unset($string[$key]);
                }
            } else {
                unset($string[$key]);
            }
        }

        if (!$translations['f']){
            $string = array_slice($string, 0, 1);
        }

        $lastParam = ($now<$ago) ? $translations['l'] : $translations['a'];
        return (!empty($string)) ? implode(', ', $string) . ' '.$lastParam : '-';
    }

    /**
     * Time zones.
     * List of supported time zones.
     * @return array
     */
    public function timezones(){
        return timezone_identifiers_list();
    }

    /**
     * Returns an array of commonly known bot names.
     *
     * @return array An array containing the names of popular bots, such as search engine crawlers and social media bots.
     */
    public function bots(){
        return ['Alexabot','AhrefsBot','Applebot','ArchiveBot','Baiduspider','Barkrowler','BLEXBot','Bingbot','BUbiNG','CCBot','Charlotte','Cliqzbot','cortex','Crawler','Discordbot','DotBot','DuckDuckBot','Embedly','ExB Language Crawler','Exabot','facebookexternalhit','Facebot','FatBot','FlipboardProxy','Flamingo_Search','Genieo','Googlebot','Google-InspectionTool', 'ia_archiver','Infohelfer','Instagram Bot','LinkedInBot','Linguee Bot','LivelapBot','LoadImpactPageAnalyzer','MagpieRSS','Mail.RU_Bot','MetaJobBot','MetaURI','MJ12bot','MojeekBot','MSRBOT','Netvibes','OpenHoseBot','OutclicksBot','Phantom','PhantomJS','Pinterest','Pinterestbot','Python-urllib','QQBrowser','Qseero','Qwantify','Redditbot','RubedoBot','SafeBrowsing','SafeDNSBot','Screaming Frog','SemrushBot','Sogou','Soso','spbot','SurveyBot','TelegramBot','Tumblrbot','Twitterbot','UnwindFetchor','VimeoBot','VoilàBot','WBSearchBot','Weibo','WhatsApp','WordPress','YandexBot','YouTubeBot'];
        
    }

    /**
     * Languages
     * Language abbreviations and country names (with local names)
     * @return array
     */
    public function languages(){
        return $this->json_decode('
        {"AB":{"name":"Abkhaz","nativeName":"аҧсуа"},"AA":{"name":"Afar","nativeName":"Afaraf"},"AF":{"name":"Afrikaans","nativeName":"Afrikaans"},"AK":{"name":"Akan","nativeName":"Akan"},"SQ":{"name":"Albanian","nativeName":"Shqip"},"AM":{"name":"Amharic","nativeName":"አማርኛ"},"AR":{"name":"Arabic","nativeName":"العربية"},"AN":{"name":"Aragonese","nativeName":"Aragonés"},"HY":{"name":"Armenian","nativeName":"Հայերեն"},"AS":{"name":"Assamese","nativeName":"অসমীয়া"},"AV":{"name":"Avaric","nativeName":"авар мацӀ, магӀарул мацӀ"},"AE":{"name":"Avestan","nativeName":"avesta"},"AY":{"name":"Aymara","nativeName":"aymar aru"},"AZ":{"name":"Azerbaijani","nativeName":"azərbaycan dili"},"BM":{"name":"Bambara","nativeName":"bamanankan"},"BA":{"name":"Bashkir","nativeName":"башҡорт теле"},"EU":{"name":"Basque","nativeName":"euskara, euskera"},"BE":{"name":"Belarusian","nativeName":"Беларуская"},"BN":{"name":"Bengali","nativeName":"বাংলা"},"BH":{"name":"Bihari","nativeName":"भोजपुरी"},"BI":{"name":"Bislama","nativeName":"Bislama"},"BS":{"name":"Bosnian","nativeName":"bosanski jezik"},"BR":{"name":"Breton","nativeName":"brezhoneg"},"BG":{"name":"Bulgarian","nativeName":"български език"},"MY":{"name":"Burmese","nativeName":"ဗမာစာ"},"CA":{"name":"Catalan; Valencian","nativeName":"Català"},"CH":{"name":"Chamorro","nativeName":"Chamoru"},"CE":{"name":"Chechen","nativeName":"нохчийн мотт"},"NY":{"name":"Chichewa; Chewa; Nyanja","nativeName":"chiCheŵa, chinyanja"},"ZH":{"name":"Chinese","nativeName":"中文 (Zhōngwén), 汉语, 漢語"},"CV":{"name":"Chuvash","nativeName":"чӑваш чӗлхи"},"KW":{"name":"Cornish","nativeName":"Kernewek"},"CO":{"name":"Corsican","nativeName":"corsu, lingua corsa"},"CR":{"name":"Cree","nativeName":"ᓀᐦᐃᔭᐍᐏᐣ"},"HR":{"name":"Croatian","nativeName":"hrvatski"},"CS":{"name":"Czech","nativeName":"česky, čeština"},"DA":{"name":"Danish","nativeName":"dansk"},"DV":{"name":"Divehi; Dhivehi; Maldivian;","nativeName":"ދިވެހި"},"NL":{"name":"Dutch","nativeName":"Nederlands, Vlaams"},"EN":{"name":"English","nativeName":"English"},"EO":{"name":"Esperanto","nativeName":"Esperanto"},"ET":{"name":"Estonian","nativeName":"eesti, eesti keel"},"EE":{"name":"Ewe","nativeName":"Eʋegbe"},"FO":{"name":"Faroese","nativeName":"føroyskt"},"FJ":{"name":"Fijian","nativeName":"vosa Vakaviti"},"FI":{"name":"Finnish","nativeName":"suomi, suomen kieli"},"FR":{"name":"French","nativeName":"français, langue française"},"FF":{"name":"Fula; Fulah; Pulaar; Pular","nativeName":"Fulfulde, Pulaar, Pular"},"GL":{"name":"Galician","nativeName":"Galego"},"KA":{"name":"Georgian","nativeName":"ქართული"},"DE":{"name":"German","nativeName":"Deutsch"},"EL":{"name":"Greek, Modern","nativeName":"Ελληνικά"},"GN":{"name":"Guaraní","nativeName":"Avañeẽ"},"GU":{"name":"Gujarati","nativeName":"ગુજરાતી"},"HT":{"name":"Haitian; Haitian Creole","nativeName":"Kreyòl ayisyen"},"HA":{"name":"Hausa","nativeName":"Hausa, هَوُسَ"},"HE":{"name":"Hebrew (modern)","nativeName":"עברית"},"HZ":{"name":"Herero","nativeName":"Otjiherero"},"HI":{"name":"Hindi","nativeName":"हिन्दी, हिंदी"},"HO":{"name":"Hiri Motu","nativeName":"Hiri Motu"},"HU":{"name":"Hungarian","nativeName":"Magyar"},"IA":{"name":"Interlingua","nativeName":"Interlingua"},"ID":{"name":"Indonesian","nativeName":"Bahasa Indonesia"},"IE":{"name":"Interlingue","nativeName":"Originally called Occidental; then Interlingue after WWII"},"GA":{"name":"Irish","nativeName":"Gaeilge"},"IG":{"name":"Igbo","nativeName":"Asụsụ Igbo"},"IK":{"name":"Inupiaq","nativeName":"Iñupiaq, Iñupiatun"},"IO":{"name":"Ido","nativeName":"Ido"},"IS":{"name":"Icelandic","nativeName":"Íslenska"},"IT":{"name":"Italian","nativeName":"Italiano"},"IU":{"name":"Inuktitut","nativeName":"ᐃᓄᒃᑎᑐᑦ"},"JA":{"name":"Japanese","nativeName":"日本語 (にほんご／にっぽんご)"},"JV":{"name":"Javanese","nativeName":"basa Jawa"},"KL":{"name":"Kalaallisut, Greenlandic","nativeName":"kalaallisut, kalaallit oqaasii"},"KN":{"name":"Kannada","nativeName":"ಕನ್ನಡ"},"KR":{"name":"Kanuri","nativeName":"Kanuri"},"KS":{"name":"Kashmiri","nativeName":"कश्मीरी, كشميري‎"},"KK":{"name":"Kazakh","nativeName":"Қазақ тілі"},"KM":{"name":"Khmer","nativeName":"ភាសាខ្មែរ"},"KI":{"name":"Kikuyu, Gikuyu","nativeName":"Gĩkũyũ"},"RW":{"name":"Kinyarwanda","nativeName":"Ikinyarwanda"},"KY":{"name":"Kirghiz, Kyrgyz","nativeName":"кыргыз тили"},"KV":{"name":"Komi","nativeName":"коми кыв"},"KG":{"name":"Kongo","nativeName":"KiKongo"},"KO":{"name":"Korean","nativeName":"한국어 (韓國語), 조선말 (朝鮮語)"},"KU":{"name":"Kurdish","nativeName":"Kurdî, كوردی‎"},"KJ":{"name":"Kwanyama, Kuanyama","nativeName":"Kuanyama"},"LA":{"name":"Latin","nativeName":"latine, lingua latina"},"LB":{"name":"Luxembourgish, Letzeburgesch","nativeName":"Lëtzebuergesch"},"LG":{"name":"Luganda","nativeName":"Luganda"},"LI":{"name":"Limburgish, Limburgan, Limburger","nativeName":"Limburgs"},"LN":{"name":"Lingala","nativeName":"Lingála"},"LO":{"name":"Lao","nativeName":"ພາສາລາວ"},"LT":{"name":"Lithuanian","nativeName":"lietuvių kalba"},"LU":{"name":"Luba-Katanga","nativeName":""},"LV":{"name":"Latvian","nativeName":"latviešu valoda"},"GV":{"name":"Manx","nativeName":"Gaelg, Gailck"},"MK":{"name":"Macedonian","nativeName":"македонски јазик"},"MG":{"name":"Malagasy","nativeName":"Malagasy fiteny"},"MS":{"name":"Malay","nativeName":"bahasa Melayu, بهاس ملايو‎"},"ML":{"name":"Malayalam","nativeName":"മലയാളം"},"MT":{"name":"Maltese","nativeName":"Malti"},"MI":{"name":"Māori","nativeName":"te reo Māori"},"MR":{"name":"Marathi (Marāṭhī)","nativeName":"मराठी"},"MH":{"name":"Marshallese","nativeName":"Kajin M̧ajeļ"},"MN":{"name":"Mongolian","nativeName":"монгол"},"NA":{"name":"Nauru","nativeName":"Ekakairũ Naoero"},"NV":{"name":"Navajo, Navaho","nativeName":"Diné bizaad, Dinékʼehǰí"},"NB":{"name":"Norwegian Bokmål","nativeName":"Norsk bokmål"},"ND":{"name":"North Ndebele","nativeName":"isiNdebele"},"NE":{"name":"Nepali","nativeName":"नेपाली"},"NG":{"name":"Ndonga","nativeName":"Owambo"},"NN":{"name":"Norwegian Nynorsk","nativeName":"Norsk nynorsk"},"NO":{"name":"Norwegian","nativeName":"Norsk"},"II":{"name":"Nuosu","nativeName":"ꆈꌠ꒿ Nuosuhxop"},"NR":{"name":"South Ndebele","nativeName":"isiNdebele"},"OC":{"name":"Occitan","nativeName":"Occitan"},"OJ":{"name":"Ojibwe, Ojibwa","nativeName":"ᐊᓂᔑᓈᐯᒧᐎᓐ"},"CU":{"name":"Old Church Slavonic, Church Slavic, Church Slavonic, Old Bulgarian, Old Slavonic","nativeName":"ѩзыкъ словѣньскъ"},"OM":{"name":"Oromo","nativeName":"Afaan Oromoo"},"OR":{"name":"Oriya","nativeName":"ଓଡ଼ିଆ"},"OS":{"name":"Ossetian, Ossetic","nativeName":"ирон æвзаг"},"PA":{"name":"Panjabi, Punjabi","nativeName":"ਪੰਜਾਬੀ, پنجابی‎"},"PI":{"name":"Pāli","nativeName":"पाऴि"},"FA":{"name":"Persian","nativeName":"فارسی"},"PL":{"name":"Polish","nativeName":"polski"},"PS":{"name":"Pashto, Pushto","nativeName":"پښتو"},"PT":{"name":"Portuguese","nativeName":"Português"},"QU":{"name":"Quechua","nativeName":"Runa Simi, Kichwa"},"RM":{"name":"Romansh","nativeName":"rumantsch grischun"},"RN":{"name":"Kirundi","nativeName":"kiRundi"},"RO":{"name":"Romanian, Moldavian, Moldovan","nativeName":"română"},"RU":{"name":"Russian","nativeName":"русский язык"},"SA":{"name":"Sanskrit (Saṁskṛta)","nativeName":"संस्कृतम्"},"SC":{"name":"Sardinian","nativeName":"sardu"},"SD":{"name":"Sindhi","nativeName":"सिन्धी, سنڌي، سندھی‎"},"SE":{"name":"Northern Sami","nativeName":"Davvisámegiella"},"SM":{"name":"Samoan","nativeName":"gagana faa Samoa"},"SG":{"name":"Sango","nativeName":"yângâ tî sängö"},"SR":{"name":"Serbian","nativeName":"српски језик"},"GD":{"name":"Scottish Gaelic; Gaelic","nativeName":"Gàidhlig"},"SN":{"name":"Shona","nativeName":"chiShona"},"SI":{"name":"Sinhala, Sinhalese","nativeName":"සිංහල"},"SK":{"name":"Slovak","nativeName":"slovenčina"},"SL":{"name":"Slovene","nativeName":"slovenščina"},"SO":{"name":"Somali","nativeName":"Soomaaliga, af Soomaali"},"ST":{"name":"Southern Sotho","nativeName":"Sesotho"},"ES":{"name":"Spanish; Castilian","nativeName":"español, castellano"},"SU":{"name":"Sundanese","nativeName":"Basa Sunda"},"SW":{"name":"Swahili","nativeName":"Kiswahili"},"SS":{"name":"Swati","nativeName":"SiSwati"},"SV":{"name":"Swedish","nativeName":"svenska"},"TA":{"name":"Tamil","nativeName":"தமிழ்"},"TE":{"name":"Telugu","nativeName":"తెలుగు"},"TG":{"name":"Tajik","nativeName":"тоҷикӣ, toğikī, تاجیکی‎"},"TH":{"name":"Thai","nativeName":"ไทย"},"TI":{"name":"Tigrinya","nativeName":"ትግርኛ"},"BO":{"name":"Tibetan Standard, Tibetan, Central","nativeName":"བོད་ཡིག"},"TK":{"name":"Turkmen","nativeName":"Türkmen, Түркмен"},"TL":{"name":"Tagalog","nativeName":"Wikang Tagalog, ᜏᜒᜃᜅ᜔ ᜆᜄᜎᜓᜄ᜔"},"TN":{"name":"Tswana","nativeName":"Setswana"},"TO":{"name":"Tonga (Tonga Islands)","nativeName":"faka Tonga"},"TR":{"name":"Turkish","nativeName":"Türkçe"},"TS":{"name":"Tsonga","nativeName":"Xitsonga"},"TT":{"name":"Tatar","nativeName":"татарча, tatarça, تاتارچا‎"},"TW":{"name":"Twi","nativeName":"Twi"},"TY":{"name":"Tahitian","nativeName":"Reo Tahiti"},"UG":{"name":"Uighur, Uyghur","nativeName":"Uyƣurqə, ئۇيغۇرچە‎"},"UK":{"name":"Ukrainian","nativeName":"українська"},"UR":{"name":"Urdu","nativeName":"اردو"},"UZ":{"name":"Uzbek","nativeName":"zbek, Ўзбек, أۇزبېك‎"},"VE":{"name":"Venda","nativeName":"Tshivenḓa"},"VI":{"name":"Vietnamese","nativeName":"Tiếng Việt"},"VO":{"name":"Volapük","nativeName":"Volapük"},"WA":{"name":"Walloon","nativeName":"Walon"},"CY":{"name":"Welsh","nativeName":"Cymraeg"},"WO":{"name":"Wolof","nativeName":"Wollof"},"FY":{"name":"Western Frisian","nativeName":"Frysk"},"XH":{"name":"Xhosa","nativeName":"isiXhosa"},"YI":{"name":"Yiddish","nativeName":"ייִדיש"},"YO":{"name":"Yoruba","nativeName":"Yorùbá"},"ZA":{"name":"Zhuang, Chuang","nativeName":"Saɯ cueŋƅ, Saw cuengh"}}');
    }

    /**
     * currencies
     * Currencies and country names
     * @return array
     */
    public function currencies(){
        return array("AED" => "United Arab Emirates dirham","AFN" => "Afghan afghani","ALL" => "Albanian lek","AMD" => "Armenian dram","ANG" => "Netherlands Antillean guilder","AOA" => "Angolan kwanza","ARS" => "Argentine peso","AUD" => "Australian dollar","AWG" => "Aruban florin","AZN" => "Azerbaijani manat","BAM" => "Bosnia and Herzegovina convertible mark","BBD" => "Barbados dollar","BDT" => "Bangladeshi taka","BGN" => "Bulgarian lev","BHD" => "Bahraini dinar","BIF" => "Burundian franc","BMD" => "Bermudian dollar","BND" => "Brunei dollar","BOB" => "Boliviano","BRL" => "Brazilian real","BSD" => "Bahamian dollar","BTN" => "Bhutanese ngultrum","BWP" => "Botswana pula","BYN" => "New Belarusian ruble","BYR" => "Belarusian ruble","BZD" => "Belize dollar","CAD" => "Canadian dollar","CDF" => "Congolese franc","CHF" => "Swiss franc","CLF" => "Unidad de Fomento","CLP" => "Chilean peso","CNY" => "Renminbi|Chinese yuan","COP" => "Colombian peso","CRC" => "Costa Rican colon","CUC" => "Cuban convertible peso","CUP" => "Cuban peso","CVE" => "Cape Verde escudo","CZK" => "Czech koruna","DJF" => "Djiboutian franc","DKK" => "Danish krone","DOP" => "Dominican peso","DZD" => "Algerian dinar","EGP" => "Egyptian pound","ERN" => "Eritrean nakfa","ETB" => "Ethiopian birr","EUR" => "Euro","FJD" => "Fiji dollar","FKP" => "Falkland Islands pound","GBP" => "Pound sterling","GEL" => "Georgian lari","GHS" => "Ghanaian cedi","GIP" => "Gibraltar pound","GMD" => "Gambian dalasi","GNF" => "Guinean franc","GTQ" => "Guatemalan quetzal","GYD" => "Guyanese dollar","HKD" => "Hong Kong dollar","HNL" => "Honduran lempira","HRK" => "Croatian kuna","HTG" => "Haitian gourde","HUF" => "Hungarian forint","IDR" => "Indonesian rupiah","ILS" => "Israeli new shekel","INR" => "Indian rupee","IQD" => "Iraqi dinar","IRR" => "Iranian rial","ISK" => "Icelandic króna","JMD" => "Jamaican dollar","JOD" => "Jordanian dinar","JPY" => "Japanese yen","KES" => "Kenyan shilling","KGS" => "Kyrgyzstani som","KHR" => "Cambodian riel","KMF" => "Comoro franc","KPW" => "North Korean won","KRW" => "South Korean won","KWD" => "Kuwaiti dinar","KYD" => "Cayman Islands dollar","KZT" => "Kazakhstani tenge","LAK" => "Lao kip","LBP" => "Lebanese pound","LKR" => "Sri Lankan rupee","LRD" => "Liberian dollar","LSL" => "Lesotho loti","LYD" => "Libyan dinar","MAD" => "Moroccan dirham","MDL" => "Moldovan leu","MGA" => "Malagasy ariary","MKD" => "Macedonian denar","MMK" => "Myanmar kyat","MNT" => "Mongolian tögrög","MOP" => "Macanese pataca","MRO" => "Mauritanian ouguiya","MUR" => "Mauritian rupee","MVR" => "Maldivian rufiyaa","MWK" => "Malawian kwacha","MXN" => "Mexican peso","MXV" => "Mexican Unidad de Inversion","MYR" => "Malaysian ringgit","MZN" => "Mozambican metical","NAD" => "Namibian dollar","NGN" => "Nigerian naira","NIO" => "Nicaraguan córdoba","NOK" => "Norwegian krone","NPR" => "Nepalese rupee","NZD" => "New Zealand dollar","OMR" => "Omani rial","PAB" => "Panamanian balboa","PEN" => "Peruvian Sol","PGK" => "Papua New Guinean kina","PHP" => "Philippine peso","PKR" => "Pakistani rupee","PLN" => "Polish złoty","PYG" => "Paraguayan guaraní","QAR" => "Qatari riyal","RON" => "Romanian leu","RSD" => "Serbian dinar","RUB" => "Russian ruble","RWF" => "Rwandan franc","SAR" => "Saudi riyal","SBD" => "Solomon Islands dollar","SCR" => "Seychelles rupee","SDG" => "Sudanese pound","SEK" => "Swedish krona","SGD" => "Singapore dollar","SHP" => "Saint Helena pound","SLL" => "Sierra Leonean leone","SOS" => "Somali shilling","SRD" => "Surinamese dollar","SSP" => "South Sudanese pound","STD" => "São Tomé and Príncipe dobra","SVC" => "Salvadoran colón","SYP" => "Syrian pound","SZL" => "Swazi lilangeni","THB" => "Thai baht","TJS" => "Tajikistani somoni","TMT" => "Turkmenistani manat","TND" => "Tunisian dinar","TOP" => "Tongan paʻanga","TRY" => "Turkish lira","TTD" => "Trinidad and Tobago dollar","TWD" => "New Taiwan dollar","TZS" => "Tanzanian shilling","UAH" => "Ukrainian hryvnia","UGX" => "Ugandan shilling","USD" => "United States dollar","UYI" => "Uruguay Peso en Unidades Indexadas","UYU" => "Uruguayan peso","UZS" => "Uzbekistan som","VEF" => "Venezuelan bolívar","VND" => "Vietnamese đồng","VUV" => "Vanuatu vatu","WST" => "Samoan tala","XAF" => "Central African CFA franc","XCD" => "East Caribbean dollar","XOF" => "West African CFA franc","XPF" => "CFP franc","XXX" => "No currency","YER" => "Yemeni rial","ZAR" => "South African rand","ZMW" => "Zambian kwacha","ZWL" => "Zimbabwean dollar"
        );
    }

    /**
     * morsealphabet
     * @param array|null $morseDictionary
     * @return array
     */
    public function morsealphabet($morseDictionary = array()){
        if(!empty($morseDictionary)){
            return $morseDictionary;
        }
        return array(
             'a' => '.-', 'b' => '-...', 'c' => '-.-.', 'ç' => '-.-..', 'd' => '-..', 'e' => '.', 'f' => '..-.', 'g' => '--.', 'ğ' => '--.-.', 'h' => '....', 'ı' => '..', 'i' => '.-..-', 'j' => '.---', 'k' => '-.-', 'l' => '.-..', 'm' => '--', 'n' => '-.', 'o' => '---', 'ö' => '---.', 'p' => '.--.', 'q' => '--.-', 'r' => '.-.', 's' => '...', 'ş' => '.--..', 't' => '-', 'u' => '..-', 'ü' => '..--', 'v' => '...-', 'w' => '.--', 'x' => '-..-', 'y' => '-.--', 'z' => '--..', '0' => '-----', '1' => '.----', '2' => '..---', '3' => '...--', '4' => '....-', '5' => '.....', '6' => '-....', '7' => '--...', '8' => '---..', '9' => '----.', '.' => '.-.-.-', ',' => '--..--', '?' => '..--..', '\'' => '.----.', '!'=> '-.-.--', '/'=> '-..-.', '(' => '-.--.', ')' => '-.--.-', '&' => '.-...', ':' => '---...', ';' => '-.-.-.', '=' => '-...-', '+' => '.-.-.', '-' => '-....-', '_' => '..--.-', '"' => '.-..-.', '$' => '...-..-', '@' => '.--.-.', '¿' => '..-.-', '¡' => '--...-', ' ' => '/',
        );
     }

     /**
     * Brings the time library of history
     * @param string $date_string 
     * @param null|string $key
     * @return null|string|array
     */
    public function getDateLib($date_string = null, $key = null) {
        // Define the month names and other date-related words for each supported language
        $language_definitions = array(
            'english' => array(
                'month_names' => 'january|february|march|april|may|june|july|august|september|october|november|december',
                'abbreviated_month_names' => 'jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec',
                'days_of_week' => 'monday|tuesday|wednesday|thursday|friday|saturday|sunday',
                'date_words' => 'today|yesterday|tomorrow',
                'date_format' => 'm/d/Y',
                'locale' => 'en_US'
            ),
            'french' => array(
                'month_names' => 'janvier|février|mars|avril|mai|juin|juillet|août|septembre|octobre|novembre|décembre',
                'abbreviated_month_names' => 'janv|févr|mars|avr|mai|juin|juil|août|sept|oct|nov|déc',
                'days_of_week' => 'lundi|mardi|mercredi|jeudi|vendredi|samedi|dimanche',
                'date_words' => 'aujourd\'hui|hier|demain',
                'date_format' => 'd/m/Y',
                'locale' => 'fr_FR'
            ),
            'german' => array(
                'month_names' => 'januar|februar|märz|april|mai|juni|juli|august|september|oktober|november|dezember',
                'abbreviated_month_names' => 'jan|feb|mär|apr|mai|jun|jul|aug|sep|okt|nov|dez',
                'days_of_week' => 'montag|dienstag|mittwoch|donnerstag|freitag|samstag|sonntag',
                'date_words' => 'heute|gestern|morgen',
                'date_format' => 'd.m.Y',
                'locale' => 'de_DE'
            ),
            'turkish' => array(
                'month_names' => 'ocak|şubat|mart|nisan|mayıs|haziran|temmuz|ağustos|eylül|ekim|kasım|aralık',
                'abbreviated_month_names' => 'oca|şub|mar|nis|may|haz|tem|ağu|eyl|eki|kas|ara',
                'days_of_week' => 'pazartesi|salı|çarşamba|perşembe|cuma|cumartesi|pazar',
                'date_words' => 'bugün|dün|yarın',
                'date_format' => 'd.m.Y',
                'locale' => 'tr_TR'
            ),
            'azerbaijani' => array(
                'month_names' => 'yanvar|fevral|mart|aprel|may|iyun|iyul|avqust|sentyabr|oktyabr|noyabr|dekabr',
                'abbreviated_month_names' => 'yan|fev|mar|apr|may|iyn|iyl|avq|sen|okt|noy|dek',
                'days_of_week' => 'bazar ertəsi|çərşənbə axşamı|çərşənbə|cümə axşamı|cümə|şənbə|bazar',
                'date_words' => 'bu gün|dünən|sabah',
                'date_format' => 'm/d/Y',
                'locale' => 'az_AZ'
            ),
            'kazakh' => array(
                'month_names' => 'қаңтар|ақпан|наурыз|сәуір|мамыр|маусым|шілде|тамыз|қыркүйек|қазан|қараша|желтоқсан',
                'abbreviated_month_names' => 'қаң|ақп|нау|сәу|мам|мау|шіл|там|қыр|қаз|қар|желт',
                'days_of_week' => 'дүйсенбі|сейсенбі|сәрсенбі|бейсенбі|жұма|сенбі|жексенбі',
                'date_words' => 'бүгін|кеңес|ертең|таңертең',
                'date_format' => 'd.m.Y', //example format for this language
                'locale' => 'kk_KZ'
            ),
            'russian' => array(
                'month_names' => 'январь|февраль|март|апрель|май|июнь|июль|август|сентябрь|октябрь|ноябрь|декабрь',
                'abbreviated_month_names' => 'янв|фев|мар|апр|май|июн|июл|авг|сен|окт|ноя|дек',
                'days_of_week' => 'понедельник|вторник|среда|четверг|пятница|суббота|воскресенье',
                'date_words' => 'сегодня|вчера|завтра',
                'date_format' => 'd.m.Y',
                'locale' => 'ru_RU'
            ),
            'chinese' => array(
                'month_names' => '一月|二月|三月|四月|五月|六月|七月|八月|九月|十月|十一月|十二月',
                'abbreviated_month_names' => '1月|2月|3月|4月|5月|6月|7月|8月|9月|10月|11月|12月',
                'days_of_week' => '星期一|星期二|星期三|星期四|星期五|星期六|星期日',
                'date_words' => '今天|昨天|明天',
                'date_format' => 'Y年m月d日',
                'locale' => 'zh_CN'
            ),
            'arabic' => array(
                'month_names' => 'كانون الثاني|شباط|آذار|نيسان|أيار|حزيران|تموز|آب|أيلول|تشرين الأول|تشرين الثاني|كانون الأول',
                'abbreviated_month_names' => 'كانون2|شباط|آذار|نيسان|أيار|حزيران|تموز|آب|أيلول|تشرين1|تشرين2|كانون1',
                'days_of_week' => 'الإثنين|الثلاثاء|الأربعاء|الخميس|الجمعة|السبت|الأحد',
                'date_words' => 'اليوم|أمس|غداً',
                'date_format' => 'd/m/Y',
                'locale' => 'ar_SA'
            ),
            'greek' => array(
                'month_names' => 'Ιανουάριος|Φεβρουάριος|Μάρτιος|Απρίλιος|Μάιος|Ιούνιος|Ιούλιος|Αύγουστος|Σεπτέμβριος|Οκτώβριος|Νοέμβριος|Δεκέμβριος',
                'abbreviated_month_names' => 'Ιαν|Φεβ|Μάρ|Απρ|Μαΐ|Ιουν|Ιουλ|Αυγ|Σεπ|Οκτ|Νοε|Δεκ',
                'days_of_week' => 'Δευτέρα|Τρίτη|Τετάρτη|Πέμπτη|Παρασκευή|Σάββατο|Κυριακή',
                'date_words' => 'σήμερα|χθες|αύριο',
                'date_format' => 'd/m/Y',
                'locale' => 'el_GR'
            ),
            'japanese' => array(
                'month_names' => '睦月|如月|弥生|卯月|皐月|水無月|文月|葉月|長月|神無月|霜月|師走',
                'abbreviated_month_names' => '睦月|如月|弥生|卯月|皐月|水無月|文月|葉月|長月|神無月|霜月|師走',
                'days_of_week' => '月曜日|火曜日|水曜日|木曜日|金曜日|土曜日|日曜日',
                'date_words' => '今日|昨日|明日',
                'date_format' => 'Y/m/d',
                'locale' => 'ja_JP'
            ),
            'armenian' => array(
                'month_names' => 'հունվար|փետրվար|մարտ|ապրիլ|մայիս|հունիս|հուլիս|օգոստոս|սեպտեմբեր|հոկտեմբեր|նոյեմբեր|դեկտեմբեր',
                'abbreviated_month_names' => 'հուն|փետ|մար|ապր|մայ|հուն|հուլ|օգս|սեպ|հոկ|նոյ|դեկ',
                'days_of_week' => 'երկուշաբթի|երեքշաբթի|չորեքշաբթի|հինգշաբթի|ուրբաթ|շաբաթ|կիրակի',
                'date_words' => 'այսօր|երեկ|վերականգ|վերականգույց|վերադաս',
                'date_format' => 'd/m/Y',
                'locale' => 'hy_AM'
            ),
            'ukrainian' => array(
                'month_names' => 'січень|лютий|березень|квітень|травень|червень|липень|серпень|вересень|жовтень|листопад|грудень',
                'abbreviated_month_names' => 'січ|лют|бер|кві|тра|чер|лип|сер|вер|жов|лис|гру',
                'days_of_week' => 'понеділок|вівторок|середа|четвер|п’ятниця|субота|неділя',
                'date_words' => 'сьогодні|вчора|завтра',
                'date_format' => 'd.m.Y',
                'locale' => 'uk_UA'
            ),
            'czech' => array(
                'month_names' => 'leden|únor|březen|duben|květen|červen|červenec|srpen|září|říjen|listopad|prosinec',
                'abbreviated_month_names' => 'led|úno|bře|dub|kvě|čer|čec|srp|zář|říj|lis|pro',
                'days_of_week' => 'pondělí|úterý|středa|čtvrtek|pátek|sobota|neděle',
                'date_words' => 'dnes|včera|zítra',
                'date_format' => 'd.m.Y',
                'locale' => 'cs_CZ'
            ),
            'polish' => array(
                'month_names' => 'styczeń|luty|marzec|kwiecień|maj|czerwiec|lipiec|sierpień|wrzesień|październik|listopad|grudzień',
                'abbreviated_month_names' => 'sty|lut|mar|kwi|maj|cze|lip|sie|wrz|paź|lis|gru',
                'days_of_week' => 'poniedziałek|wtorek|środa|czwartek|piątek|sobota|niedziela',
                'date_words' => 'dzisiaj|wczoraj|jutro',
                'date_format' => 'd.m.Y',
                'locale' => 'pl_PL'
            ),
            'latvian' => array(
                'month_names' => 'janvāris|februāris|marts|aprīlis|maijs|jūnijs|jūlijs|augusts|septembris|oktobris|novembris|decembris',
                'abbreviated_month_names' => 'jan|feb|mar|apr|mai|jūn|jūl|aug|sep|okt|nov|dec',
                'days_of_week' => 'pirmdiena|otrdiena|trešdiena|ceturtdiena|piektdiena|sestdiena|svētdiena',
                'date_words' => 'šodien|vakar|rīt',
                'date_format' => 'd.m.Y',
                'locale' => 'lv_LV'
            ),
            'romanian' => array(
                'month_names' => 'ianuarie|februarie|martie|aprilie|mai|iunie|iulie|august|septembrie|octombrie|noiembrie|decembrie',
                'abbreviated_month_names' => 'ian|feb|mar|apr|mai|iun|iul|aug|sep|oct|nov|dec',
                'days_of_week' => 'luni|marți|miercuri|joi|vineri|sâmbătă|duminică',
                'date_words' => 'azi|ieri|maine',
                'date_format' => 'd.m.Y',
                'locale' => 'ro_RO'
            ),
            'italian' => array(
                'month_names' => 'gennaio|febbraio|marzo|aprile|maggio|giugno|luglio|agosto|settembre|ottobre|novembre|dicembre',
                'abbreviated_month_names' => 'gen|feb|mar|apr|mag|giu|lug|ago|set|ott|nov|dic',
                'days_of_week' => 'lunedì|martedì|mercoledì|giovedì|venerdì|sabato|domenica',
                'date_words' => 'oggi|ieri|domani',
                'date_format' => 'd/m/Y',
                'locale' => 'it_IT' 
            ),
            'spanish' => array(
                'month_names' => 'enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre',
                'abbreviated_month_names' => 'ene|feb|mar|abr|may|jun|jul|ago|sep|oct|nov|dic',
                'days_of_week' => 'lunes|martes|miércoles|jueves|viernes|sábado|domingo',
                'date_words' => 'hoy|ayer|mañana',
                'date_format' => 'd/m/Y',
                'locale' => 'es_ES'
            ),
            'portuguese' => array(
                'month_names' => 'janeiro|fevereiro|março|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro',
                'abbreviated_month_names' => 'jan|fev|mar|abr|mai|jun|jul|ago|set|out|nov|dez',
                'days_of_week' => 'segunda-feira|terça-feira|quarta-feira|quinta-feira|sexta-feira|sábado|domingo',
                'date_words' => 'hoje|ontem|amanhã',
                'date_format' => 'd/m/Y',
                'locale' => 'pt_PT'
            )
        );
        
        if(is_null($date_string) AND is_null($key)){
            return $language_definitions;
        }
        
        $detect_month = '';
        if(!is_null($date_string)){
            preg_match("/\b\p{L}+\b(?=\W+\d{4})/u", $date_string, $matches);
            if (count($matches) > 0) {
                $detect_month = mb_strtolower($matches[0]);
            } 
        }

        // Loop through each language and check if the input string matches the defined patterns
        foreach ($language_definitions as $language => $definitions) {
           
            if($definitions['locale'] == $key){
                return $definitions;
            } else {
                if(!is_null($date_string)){
                    foreach (explode('|', $definitions['month_names']) as $nkey => $month){
                        
                        if($detect_month == $month){
                            $date_string = str_ireplace($month, explode('|', $language_definitions['english']['month_names'])[$nkey], $date_string);
                            if(isset($definitions[$key])) { 
                                return $definitions[$key]; 
                            } 
                            return $definitions; 
                        }

                    }

                }
                
            }
        }

        // If none of the languages matched, return null
        return $language_definitions['english'];
    }
     
    /**
     * Session checking.
     *
     * @return array $_SESSION
     */
    public function session_check(){

        $this->session_path = (is_null($this->session_path)) ? sys_get_temp_dir().'/' : $this->session_path;

        if(!is_dir($this->session_path)){
            mkdir($this->session_path); 
            chmod($this->session_path, 755);
            $this->policyMaker();
        }

        if(is_dir($this->session_path)){
            ini_set('session.save_path',$this->session_path);
        }

        if(!isset($_SESSION)){
            session_start();
        }
        
    }

    /**
     * Learns the size of the remote file.
     *
     * @param string $url
     * @return int
     */
    function remoteFileSize( $url ) {
        $filesize = -1;
        if($this->is_http($url) OR $this->is_https($url)){
            $headers = get_headers($url, 1);
            $filesize = $headers['Content-Length'];
        } 
        return $filesize;
      }

    /**
     * Layer installer
     *
     * @param mixed $file
     * @param mixed $cache
     * @return void
     */

    public function addLayer($file = null, $cache = null, $permissions = null){
        

        if(!is_null($permissions)){
            if(!$this->permission_verification($permissions)){
                return'';
            }
        }

        // layer extension
        $ext = '.php';

        // layer set
        $layers = [];

        // temporary layers
        $tempLayers = [];

        // Cache layers are taken into account
        if(!is_null($cache) AND !is_array($cache)) $layers[] = $cache;
        if(!is_null($cache) AND is_array($cache)) foreach($cache as $c) { $layers[] = $c; }

        // File layers are taken into account
        if(!is_null($file) AND !is_array($file)) $layers[] = $file;
        if(!is_null($file) AND is_array($file)) foreach($file as $f) { $layers[] = $f; }

        // All layers are run sequentially
        foreach ($layers as $key => $layer) {
            $this->monitor['layer'][] = $layer; 
            $tempLayers[$key] = $this->wayMaker($layer);
        }

        // Layers are being processed
        foreach ($tempLayers as $layer) {
            
            // Checking for layer existence
            if(file_exists($layer['way'].$ext)) { 
                require_once($layer['way'].$ext); 
                // The class name is extracted from the layer path
                $className = basename($layer['way']);

                // If the class exists, it is assigned to the variable
                if(class_exists($className)){ 
                    $class = new $className();
                    if(isset($class->post)){
                        (array)$class->post = $this->post;
                    }
                    
                    // If the method exists, it is executed.
                    if(isset($layer['params'])){ 
                        foreach ($layer['params'] as $param) { 
                            $class->$param(); 
                        } 
                    }
                    
                    // If there are errors in the inner class, they are 
                    // passed to the Mind class.
                    if(isset($class->monitor['errors'])) {
                        foreach($class->monitor['errors'] as $error){
                            if(!empty($error)) { $this->monitor['errors'][] = $error; }
                        }
                    }
                
                    if(!empty($class->monitor['db'])){
                        foreach($class->monitor['db'] as $mondb){
                            $this->monitor['db'][md5($class->sql)] = $mondb;
                        }
                    }

                    if(!empty($class->monitor['layer'])){
                        foreach($class->monitor['layer'] as $monlayer){
                            $this->monitor['layer'][] = $monlayer;
                        }
                    }

                    if(!empty($class->monitor['route'])){
                        foreach($class->monitor['route']['params'] as $layerparams){
                            $this->monitor['route']['params'][] = $layerparams;
                        }
                    }
                    
                }
            }   

        }

     }

    /**
     * Column sql syntax creator.
     *
     * @param array $scheme
     * @param string $funcName
     * @return array
     */
    public function columnSqlMaker($scheme, $funcName=null){

        $sql = [];
        $column = [];
        $primary_key = null;

        foreach (array_values($scheme) as $array_value) {
            
            $column = $this->wayMaker($array_value);
            $type = (isset($column['params'][0])) ? $column['params'][0] :  'small';
            
            switch ($type) {
                case 'int':
                    switch ($this->db['drive']) {
                        case 'mysql':
                            $value = (isset($column['params'][1])) ? $column['params'][1] : 11;
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD `'.$column['way'].'` INT('.$value.') NULL DEFAULT NULL' : '`'.$column['way'].'` INT('.$value.') NULL DEFAULT NULL';
                        break;
                        case 'sqlsrv':
                            $value = (isset($column['params'][1])) ? $column['params'][1] : 11;
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD '.$column['way'].' INT NULL DEFAULT NULL' : $column['way'].' INT NULL DEFAULT NULL';
                        break;
                        case 'sqlite':  
                            $value = (isset($column['params'][1])) ? $column['params'][1] : 11;
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD COLUMN `'.$column['way'].'` INT('.$value.') NULL DEFAULT NULL' : '`'.$column['way'].'` INT('.$value.') NULL DEFAULT NULL';
                        break;   
                    } 
                break;
                case 'decimal':
                    switch ($this->db['drive']) {
                        case 'mysql':
                            $value = (isset($column['params'][1])) ? $column['params'][1] : '6,2';
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD `'.$column['way'].'` DECIMAL('.$value.') NULL DEFAULT NULL' : '`'.$column['way'].'` DECIMAL('.$value.') NULL DEFAULT NULL';
                        break;
                        case 'sqlsrv':
                            $value = (isset($column['params'][1])) ? $column['params'][1] : '6,2';
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD '.$column['way'].' DECIMAL('.$value.') NULL DEFAULT NULL' : $column['way'].' DECIMAL('.$value.') NULL DEFAULT NULL';
                        break;
                        case 'sqlite':  
                            $value = (isset($column['params'][1])) ? $column['params'][1] : '6,2';
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD COLUMN `'.$column['way'].'` DECIMAL('.$value.') NULL DEFAULT NULL' : '`'.$column['way'].'` DECIMAL('.$value.') NULL DEFAULT NULL';
                        break;   
                    }  
                break;
                case 'string':
                    switch ($this->db['drive']) {
                        case 'mysql':
                            $value = (isset($column['params'][1])) ? $column['params'][1] : 255;
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD `'.$column['way'].'` VARCHAR('.$value.') NULL DEFAULT NULL' : '`'.$column['way'].'` VARCHAR('.$value.') NULL DEFAULT NULL';
                        break;
                        case 'sqlsrv':
                            $value = (isset($column['params'][1])) ? $column['params'][1] : 255;
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD '.$column['way'].' NVARCHAR('.$value.') NULL DEFAULT NULL' : $column['way'].' NVARCHAR('.$value.') NULL DEFAULT NULL';
                        break;
                        case 'sqlite':  
                            $value = (isset($column['params'][1])) ? $column['params'][1] : 255;                          
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD COLUMN `'.$column['way'].'` VARCHAR('.$value.') NULL DEFAULT NULL' : '`'.$column['way'].'` VARCHAR('.$value.') NULL DEFAULT NULL';
                        break;   
                    }                    
                break;
                case 'small':
                    switch ($this->db['drive']) {
                        case 'mysql':
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD `'.$column['way'].'` TEXT NULL DEFAULT NULL' : '`'.$column['way'].'` TEXT NULL DEFAULT NULL';
                        break;
                        case 'sqlsrv':
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD '.$column['way'].' NVARCHAR(MAX) NULL DEFAULT NULL' : $column['way'].' NVARCHAR(MAX) NULL DEFAULT NULL';
                        break;
                        case 'sqlite':
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD COLUMN `'.$column['way'].'` TEXT NULL DEFAULT NULL' : '`'.$column['way'].'` TEXT NULL DEFAULT NULL';
                        break;   
                    }
                break;
                case 'medium':
                    switch ($this->db['drive']) {
                        case 'mysql':
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD `'.$column['way'].'` MEDIUMTEXT NULL DEFAULT NULL' : '`'.$column['way'].'` MEDIUMTEXT NULL DEFAULT NULL';
                        break;
                        case 'sqlsrv':
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD '.$column['way'].' NTEXT NULL DEFAULT NULL' : $column['way'].' NTEXT NULL DEFAULT NULL';
                        break;
                        case 'sqlite':
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD COLUMN `'.$column['way'].'` MEDIUMTEXT NULL DEFAULT NULL' : '`'.$column['way'].'` MEDIUMTEXT NULL DEFAULT NULL';
                        break;   
                    }
                break;
                case 'large':
                    switch ($this->db['drive']) {
                        case 'mysql':
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD `'.$column['way'].'` LONGTEXT NULL DEFAULT NULL' : '`'.$column['way'].'` LONGTEXT NULL DEFAULT NULL'; 
                        break;
                        case 'sqlsrv':
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD '.$column['way'].' NTEXT NULL DEFAULT NULL' : $column['way'].' NTEXT NULL DEFAULT NULL';
                        break;
                        case 'sqlite':
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD COLUMN `'.$column['way'].'` LONGTEXT NULL DEFAULT NULL' : '`'.$column['way'].'` LONGTEXT NULL DEFAULT NULL'; 
                        break;   
                    }
                break;
                case 'increments':
                    switch ($this->db['drive']) {
                        case 'mysql':
                            $value = (isset($column['params'][1])) ? $column['params'][1] : 11;
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD `'.$column['way'].'` INT('.$value.') NOT NULL AUTO_INCREMENT FIRST' : '`'.$column['way'].'` INT('.$value.') NOT NULL AUTO_INCREMENT';
                            $primary_key = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD PRIMARY KEY (`'.$column['way'].'`)' : 'PRIMARY KEY (`'.$column['way'].'`)';
                        break;
                        case 'sqlsrv':
                            $value = (isset($column['params'][1])) ? $column['params'][1] : 11;
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD '.$column['way'].' INT PRIMARY KEY IDENTITY(1,1) NOT NULL' : $column['way'].' INT IDENTITY(1,1) NOT NULL';
                            // $primary_key = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD '.$column['way'].' INT PRIMARY KEY IDENTITY(1,1) NOT NULL' : $column['way'].' INT IDENTITY(1,1) NOT NULL';
                        break;
                        case 'sqlite':
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD COLUMN `'.$column['way'].'` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL' : '`'.$column['way'].'` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL';
                        break;
                            
                    }
                   
                break;
                
            }
        }
        
        // for mysql
        if(!is_null($primary_key)){ $sql[] = $primary_key; }

        return $sql;

        
    }

    /**
     * Layer and method parser.
     *
     * @param string $str
     * @return array
     */
    public function wayMaker($str=''){

        // The output variable is being created.
        $output = [];

        // The parameter variable is being created.
        $assets = [];

        // The layer and parameter are being parsed.
        if(strstr($str, ':')){ 
            $assets = explode(':', trim($str, ':')); } else { $assets[] = $str; }

        // If there is no layer parameter, only the layer is defined
        if(count($assets) == 1){ $output['way'] = $assets[0]; }

        // If there is a layer and its parameter, it is assigned to the variable.
        if(count($assets) == 2){ list($output['way'], $output['params']) = $assets; }

        // Parameters are obtained
        if(isset($output['params'])){
            $output['params'] = (strstr($output['params'], '@')) ? explode('@', trim($output['params'], '@')) : $output['params'] = [$output['params']];
        } else {
            $output['params'] = [];
        }
        
        return $output;
    }

    /**
     * Token generator
     * 
     * @param int $length
     * @return string
     */
    public function generateToken($length=100){
        $key = '';
        $keys = array_merge(range('A', 'Z'), range(0, 9), range('a', 'z'), range(0, 9));

        for ($i = 0; $i < $length; $i++) {
            $key .= $keys[array_rand($keys)];
        }

        return $key;
    }

    /** 
     * Returns astronomy data.
     * @param array $request Request array containing sun and moon data.
     * @return array Response array containing sun and moon phases.
    */
    public function astronomy($request) {
        
        $response = [];

        if(isset($request['sun'])){
            $sun = [
                'lat'=>$request['sun']['lat'],
                'lon'=>$request['sun']['lon'],
                'timestamp'=>!isset($request['sun']['timestamp']) ? null : $request['sun']['timestamp'],
                'timezone'=>!isset($request['sun']['timezone']) ? null : $request['sun']['timezone']
            ];
            $response['sun'] = $this->getSunPhase($sun['lat'], $sun['lon'], $sun['timestamp'], $sun['timezone']);
        }

        if(isset($request['moon'])){
            $moon = [
                'timestamp'=>!isset($request['moon']['timestamp']) ? null : $request['moon']['timestamp'],
                'timezone'=>!isset($request['moon']['timezone']) ? null : $request['moon']['timezone']
            ];
            $response['moon'] = $this->getMoonPhase($moon['timestamp'], $moon['timezone']);
        }
        return $response;
    }

    /**
     * Retrieves the sun phases.
     *
     * @param float $lat Latitude information.
     * @param float $lon Longitude information.
     * @param int|null $timestamp Timestamp (default: current timestamp).
     * @param string|null $timezone Timezone (default: current timezone).
     * 
     * @return array An array containing sun phase information.
     */
    public function getSunPhase($lat, $lon, $timestamp = null, $timezone = null)
    {
        $timestamp = is_null($timestamp) ? $this->timestamp : $timestamp;
        $timestamp = $this->is_date($timestamp) ? strtotime($timestamp) : $timestamp;

        if ($this->is_timezone($timezone)) {
            date_default_timezone_set($timezone);
        }

        $sun_info_all = [
            'timezone'=>is_null($timezone) ? $this->timezone : $timezone,
            'timestamp'=>$this->timestamp,
            'latitude'=>$lat,
            'longitude'=>$lon
        ];

        $sun_info = date_sun_info($timestamp, $lat, $lon);        
        $formatted_sun_info = array_map(function($time) { return date('Y-m-d H:i:s', $time); }, $sun_info);

        $sun_info_all = array_merge($sun_info_all, $formatted_sun_info);

        if (is_null($timezone)) {
            date_default_timezone_set($this->timezone);
        }
        

        return $sun_info_all;
    }

    /**
     * Retrieves the moon phase information.
     *
     * @param int|string $timestamp The timestamp or date string.
     * @param string|null $timezone The timezone (default: current timezone).
     * 
     * @return array An array containing moon phase information.
     */
    public function getMoonPhase($timestamp, $timezone = null) {
    
        $unixdate = $this->is_date($timestamp) ? strtotime($timestamp) : $timestamp;
        
        // If time zone is specified, use it.
        if($this->is_timezone($timezone)){
            date_default_timezone_set($timezone);
        }

        // The duration in days of a lunar cycle
        $lunardays = 29.53058770576;
        // Seconds in lunar cycle
        $lunarsecs = $lunardays * (24 * 60 *60);

        // Current first new month start timestamp
        $new2024 = strtotime("2024-01-11 11:39:00");

        // Calculate seconds between date and new moon 2024
        $totalsecs = $unixdate - $new2024;

        // Calculate modulus to drop completed cycles
        // Note: for real numbers use fmod() instead of % operator
        $currentsecs = fmod($totalsecs, $lunarsecs);

        // If negative number (date before new moon 2024) add $lunarsecs
        if ( $currentsecs < 0 ) {
            $currentsecs += $lunarsecs;
        }

        // Calculate the fraction of the moon cycle
        $currentfrac = $currentsecs / $lunarsecs;

        // Calculate days in current cycle (moon age)
        $currentdays = $currentfrac * $lunardays;

        // Array with start and end of each phase
        // In this array 'new', 'first quarter', 'full' and
        // 'last quarter' each get a duration of 2 days.
        $phases = array
            (
                array("New Moon", 0, 1), // Yeni Ay 
                array("Waxing Crescent", 1, 6.38264692644), // Hilal 
                array("First Quarter", 6.38264692644, 8.38264692644), // İlk Dördün 
                array("Waxing Gibbous", 8.38264692644, 13.76529385288), // Kambur Ay 
                array("Full Moon", 13.76529385288, 15.76529385288), // Dolunay 
                array("Waning Gibbous", 15.76529385288, 21.14794077932), // Solan Kambur Ay 
                array("Last Quarter", 21.14794077932, 23.14794077932), // Son Dördün 
                array("Waning Crescent", 23.14794077932, 28.53058770576), // Balzamik Ay 
                array("Old Moon", 28.53058770576, 29.53058770576), // Eski Ay
            );

            // Find current phase in the array  
            $thephase = null;
            $phase_start = null;
            $phase_end = null;

            foreach ($phases as $phase) {
                if ($currentdays >= $phase[1] && $currentdays <= $phase[2]) {
                    $thephase = $phase[0];

                    // Adjust phase dates based on current timestamp  
                    $phase_start = intval($unixdate - ($currentsecs - ($phase[1] / $lunardays) * $lunarsecs));
                    $phase_end = intval($unixdate - ($currentsecs - ($phase[2] / $lunardays) * $lunarsecs));

                    break;
                }
            }


            $result = [         
                'timezone'=>$this->is_timezone($timezone) ? $timezone : $this->timezone,
                'timestamp'=> date("Y-m-d H:i:s", $unixdate),
                'lunar_cycle_constant'=> $lunardays,
                'percentage_of_lunation'=> round($currentdays, 3),
                'phase'=> $thephase,
                'phase_start'=> date("Y-m-d H:i:s", $phase_start),
                'phase_end'=> date("Y-m-d H:i:s", $phase_end),
            ];
        

        // If time zone has been changed, revert to default.
        if($this->is_timezone($timezone)){
            date_default_timezone_set($this->timezone);
        }

        return $result;
    }
    
    /**
     * Coordinates marker
     * 
     * @param string $element
     * @return string|null It interferes with html elements.
     */
    public function coordinatesMaker($element='#coordinates'){
        $element = $this->filter($element);
        ?>
        <script>
            

            function getLocation() {
                let = elements = document.querySelectorAll("<?=$element;?>");
                if (navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(redirectToPosition);
                } else { 
                    console.log("Geolocation is not supported by this browser.");
                    elements.forEach(function(element) {
                        element.value = null;
                    });
                }
            }

            function redirectToPosition(position) {
                let elements = document.querySelectorAll("<?=$element;?>");
                let coordinates = position.coords.latitude+','+position.coords.longitude;
                if(elements.length >= 1){

                    elements.forEach(function(element) {
                        if(element.value === undefined){
                            element.textContent = coordinates;
                        } else {
                            element.value = coordinates;
                        }
                    });
                } else {
                    console.log("The item was not found.");
                }
            }
            
            getLocation();
        </script>

        <?php
    }

    /**
     * Converts tile coordinates (x, y) and zoom level to 
     * latitude and longitude.
     *
     * @param int $zoom The zoom level.
     * @param int $x The x coordinate (tile).
     * @param int $y The y coordinate (tile).
     * @return array An associative array containing 'zoom', 'lat' 
     * (latitude), and 'lon' (longitude).
     */
    public function tileToLatLon($zoom, $x, $y) {
        $n = pow(2, $zoom);
        $lon_deg = $x / $n * 360.0 - 180.0;
        $lat_rad = atan(sinh(pi() * (1 - 2 * $y / $n)));
        $lat_deg = rad2deg($lat_rad);
        return ['zoom' => $zoom, 'lat' => $lat_deg, 'lon' => $lon_deg];
    }

    /**
     * Converts latitude and longitude to tile coordinates (x, y) at 
     * a given zoom level.
     *
     * @param int $zoom The zoom level.
     * @param float $lat The latitude.
     * @param float $lon The longitude.
     * @return array An associative array containing 'z' (zoom), 'x' 
     * (tile x coordinate), and 'y' (tile y coordinate).
     */
    public function latLonToTile($zoom, $lat, $lon) {
        $lat_rad = deg2rad($lat);
        $n = pow(2, $zoom);
        $x_tile = floor(($lon + 180.0) / 360.0 * $n);
        $y_tile = floor((1.0 - log(tan($lat_rad) + 1.0 / cos($lat_rad)) / pi()) / 2.0 * $n);
        return ['z' => $zoom, 'x' => $x_tile, 'y' => $y_tile];
    }


    /**
     * Convert size
     * @param string|int $size
     * @param string|int $precision
     * @return string|bool
     */
    public function encodeSize($size, $precision = 2)
    {
        $sizeLibrary = array('B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB');

        if(isset($size['size'])){
            $size = $size['size'];
        }
        $size = (isset($size['size'])) ? $size['size'] : $size;

        if(!is_numeric($size)){ return false; }
        if($size == 0){ return '0 B'; }
    
        if(!strstr($size, ' ')){
            $exp = floor(log($size, 1024)) | 0;
            $exp = min($exp, count($sizeLibrary) - 1);
            return round($size / (pow(1024, $exp)), $precision).' '.$sizeLibrary[$exp];
        }

        return false;
    }

    /**
     * Convert byte
     * @param string|int $size
     * @return int|bool
     */
    public function decodeSize($size)
    {

        $sizeLibrary = array('B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB');

        if(!strstr($size, ' ')){
            $key = mb_strlen($size) - 1;
            if($size[$key] == 'B'){
                $size = mb_substr( $size, 0, $key).' B';
            } else {
                $size = mb_substr( $size, 0, $key).' '.$size[$key].'B';
            }

        }

        if(strstr($size, ' ')){

            if(count(explode(' ', $size)) === 2){
                list($number, $format) = explode(' ', $size);
                $id = array_search($format, $sizeLibrary);
                return $number*pow(1024, $id);
            } 
        }

        return false;

    }

    /**
     * to seconds
     * @param string $time
     * @return int|bool
     */
    public function toSeconds($time){
        if(strstr($time, ':')){
            $timeArr = explode(':', $time);
            if(count($timeArr) < 2){ return false; }
            list($hours, $mins, $secs) = (count($timeArr) === 3) ? $timeArr : [$timeArr[0], $timeArr[1], 00];
            if(!is_numeric($hours) OR !is_numeric($mins) OR !is_numeric($secs)){ return false; }
            if($mins>59 OR $secs>59){ return false; }

            return ($hours * 3600 ) + ($mins * 60 ) + $secs;            
        }
        return false;
    }

    /**
     * to time
     * @param int $seconds
     * @return string|bool
     */
    public function toTime($seconds){
        if(is_numeric($seconds)){
            $hours = floor($seconds / 3600);
            $mins = floor(($seconds - ($hours * 3600)) / 60);
            $secs = $seconds - ($hours * 3600) - ($mins * 60);
            return sprintf('%02d:%02d:%02d', $hours, $mins, $secs);
        }
        return false;
    }

    /**
     * Converts a date containing ISO 8601 syntax to 
     * RFC 3339 syntax.
     *
     * @param string $date The date to convert.
     * @return string The date in RFC 3339 format.
     */
    public function toRFC3339($date){
        if(is_null($date) || !strtotime($date)) {
            return null;
        }
        $dateObject  = new DateTime($date);
        return $dateObject->format("Y-m-d\TH:i:sP");
    }

    /**
     * Converts dates containing RFC3339 and ISO 8601 
     * syntax to a full ISO 8601 syntax (Y-m-d H:i:s).
     *
     * @param string $date The date to convert.
     * @return string The date in RFC 3339 format.
     */
    public function toISO8601($date){
        if(is_null($date) || !strtotime($date)) {
            return null;
        }
        $dateTime = new DateTime($date);
        return $dateTime->format("Y-m-d H:i:s");
    }

    /**
     * Summary
     * @param string $str
     * @param int $length
     * @param string|null $more 
     * @param boolean|null $filter 
     * @return string
     */
    public function summary($str, $length, $more = '', $filter = true){

        if($filter === true) { 

            $str = preg_replace([
                "/\n|\r|\t|&nbsp;/", // line, tab gaps, space
                "/\s*,\s*/", // Gap after the comma
                "/<[^>]*>/", // Code is blocked
                "/&apos;/", // single nail 
                "/&quot;/", // double quotes
            ], [
                " ", ", ", "", "'", "\""
            ], $str);
            $str = trim(rtrim($str, ',|"|\'|&'));
        }
        return mb_substr($str, 0, $length, 'UTF-8').((mb_strlen($str, 'UTF-8') > $length) ? $more : '');
    }

    /**
     * @return string
     */
    public function getIPAddress(){
        if($_SERVER['REMOTE_ADDR'] === '::1'){
            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        }
        return $_SERVER['REMOTE_ADDR'];
    }

    /**
     * @return string
     */
    public function getLang(){
        return mb_strtoupper(mb_substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2));
    }

    /**
     * @return array
     */
    public function blood_groups() {
        $blood_groups = [
            'AB+' => ['AB+', 'AB-', 'B+', 'B-', 'A+', 'A-', '0+', '0-'],
            'AB-' => ['AB-', 'B-', 'A-', '0-'],
            'B+' => ['B+', 'B-', '0+', '0-'],
            'B-' => ['B-', '0-'],
            'A+' => ['A+', 'A-', '0+', '0-'],
            'A-' => ['A-', '0-'],
            '0+' => ['0+', '0-'],
            '0-' => ['0-']
        ];
    
        return $blood_groups;
    }

    /**
     * @param string $blood
     * @return array
     */
    public function getDonors($blood){
        $bloods = $this->blood_groups();

        if(!in_array($blood, array_keys($bloods))){            
            return [];
        }
        
        return $bloods[mb_strtoupper($blood)];
    }

    /**
     * @param string $blood
     * @return array
     */
    public function getRecipients($blood){
        $bloods = $this->blood_groups();

        $towhom = [];
        foreach ($bloods as $group => $donors) {
            if (in_array($blood, $donors)) {
                $towhom[] = $group;
            }
        }
        return $towhom;
    }

    /**
     * getAddressCode
     * 
     * @param string|array $address
     * @param string|array|null $status
     * @return array
     */
    public function getAddressCode($address, $status=null){

        $result = array();
        $statusList = array();
        if(!is_array($address)){
            $address = array($address);
        }

        if(!is_null($status)){
            if(!is_array($status)){
                $status = array($status);
            }

            foreach ($status as $key => $code) {
                if(!in_array($code, array_keys($this->addressCodeList()))){
                    return $result;
                } else {
                    $statusList[] = $code;
                }
            }
        } else {
            $statusList = array_keys($this->addressCodeList());
        }
        
        $mh = curl_multi_init();
		foreach($address as $key => $value){
            $ch[$key] = curl_init($value);
			curl_setopt($ch[$key], CURLOPT_TIMEOUT, 1);
			curl_setopt($ch[$key], CURLOPT_HEADER, 0);
			curl_setopt($ch[$key], CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch[$key], CURLOPT_TIMEOUT, 1);
			curl_setopt($ch[$key], CURLOPT_VERBOSE, 0);
			curl_multi_add_handle($mh, $ch[$key]);
		}
		do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh);
          } while ($running > 0);

          foreach(array_keys($ch) as $key){
			$httpcode = curl_getinfo($ch[$key], CURLINFO_HTTP_CODE);
            if(in_array($httpcode, $statusList)){
                $result[$key] = array(
                    'code' => $httpcode,
                    'address' => $address[$key],
                    'timestamp' => $this->timestamp
                  );
            }
			curl_multi_remove_handle($mh, $ch[$key]);
		}
		curl_multi_close($mh);
        
        return $result;
    }
    
    /**
     * addressCodeList
     * @return array
     * 
     */
    public function addressCodeList(){
        $httpStatusCodes = array(0 => "Not defined",100 => "Continue",101 => "Switching Protocols",102 => "Processing",200 => "OK",201 => "Created",202 => "Accepted",203 => "Non-Authoritative Information",204 => "No Content",205 => "Reset Content",206 => "Partial Content",207 => "Multi-Status",208 => "Already Reported",226 => "IM Used",300 => "Multiple Choices",301 => "Moved Permanently",302 => "Found",303 => "See Other",304 => "Not Modified",305 => "Use Proxy",306 => "(Unused)",307 => "Temporary Redirect",308 => "Permanent Redirect",400 => "Bad Request",401 => "Unauthorized",402 => "Payment Required",403 => "Forbidden",404 => "Not Found",405 => "Method Not Allowed",406 => "Not Acceptable",407 => "Proxy Authentication Required",408 => "Request Timeout",409 => "Conflict",410 => "Gone",411 => "Length Required",412 => "Precondition Failed",413 => "Payload Too Large",414 => "URI Too Long",415 => "Unsupported Media Type",416 => "Range Not Satisfiable",417 => "Expectation Failed",418 => "I'm a teapot",419 => "Authentication Timeout",420 => "Method Failure",422 => "Unprocessable Entity",423 => "Locked",424 => "Failed Dependency",425 => "Unordered Collection",426 => "Upgrade Required",428 => "Precondition Required",429 => "Too Many Requests",431 => "Request Header Fields Too Large",444 => "Connection Closed Without Response",449 => "Retry With",450 => "Blocked by Windows Parental Controls",451 => "Unavailable For Legal Reasons",494 => "Request Header Too Large",495 => "Cert Error",496 => "No Cert",497 => "HTTP to HTTPS",499 => "Client Closed Request",500 => "Internal Server Error",501 => "Not Implemented",502 => "Bad Gateway",503 => "Service Unavailable",504 => "Gateway Timeout",505 => "HTTP Version Not Supported",506 => "Variant Also Negotiates",507 => "Insufficient Storage",508 => "Loop Detected",509 => "Bandwidth Limit Exceeded",510 => "Not Extended",511 => "Network Authentication Required",598 => "Network read timeout error",599 => "Network connect timeout error");
        
        return $httpStatusCodes;
    }

    /**
     * Address Generator
     * 
     */
    public function addressGenerator($start, $end, $type="ipv4"){

        $result = array();

        if(empty($type)){
            return $result;
        }

        switch ($type) {
            case 'ipv4':

                if(!$this->is_ipv4($start) OR !$this->is_ipv4($end)){
                    return $result;
                }

                if($start>$end){
                    $x = $start; $start = $end; $end = $x; unset($x);
                }

                list($aa, $bb, $cc, $dd) = explode('.', $start);
                for ($a=$aa; $a <= 255; $a++) { 
                    for ($b=$bb; $b <= 255; $b++) { 
                        for ($c=$cc; $c <= 255; $c++) { 
                            for ($d=$dd; $d <= 255; $d++) { 
                                if ($a.'.'.$b.'.'.$c.'.'.$d == $end) {	
                                    $result[] = $a.'.'.$b.'.'.$c.'.'.$d;			
                                    break;
                                }	
                                $result[] = $a.'.'.$b.'.'.$c.'.'.$d;
                                $dd = 0;
                            }
                            if ($a.'.'.$b.'.'.$c.'.'.$d == $end) {				
                                break;
                            }	
                            $cc = 0;
                        }
                        if ($a.'.'.$b.'.'.$c.'.'.$d == $end) {				
                            break;
                        }	
                        $bb = 0;
                    }
                    if ($a.'.'.$b.'.'.$c.'.'.$d == $end) {				
                        break;
                    }	
                    $aa = 0;
                }	
            break;

            case 'ipv6':
                
                $start = inet_pton($start);
                $end = inet_pton($end);
                $current = $start;
                $result = [];

                while ($current !== $end) {
                    $result[] = inet_ntop($current);

                    for ($i = 15; $i >= 0; $i--) {
                        if (ord($current[$i]) === 255) {
                            $current[$i] = chr(0);
                        } else {
                            $current[$i] = chr(ord($current[$i]) + 1);
                            break;
                        }
                    }
                }

            break;

            case 'onion':
                $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
                $start = (strstr($start, '.')) ? explode('.', $start)[0] : $start;
                $end = (strstr($end, '.')) ? explode('.', $end)[0] : $end;

                $start = str_pad($start, 16, '0');
                $end = str_pad($end, 16, 'z');

                $current = $start;
                $result = array();
                while ($current != $end) {
                    array_push($result, $current.'.onion');

                    for ($i = 15; $i >= 0; $i--) {
                        $char = $alphabet[strpos($alphabet, $current[$i]) + 1];
                        if ($char === false) {
                            $current = substr_replace($current, '0', $i, 1);
                        } else {
                            $current = substr_replace($current, $char, $i, 1);
                            break;
                        }
                    }
                }
            break;
            
        }

        return $result;
        
    }

     /**
     * Board reviewing the dataset.
     * 
     * The first parameter dataset. The second parameter is 
     * the allowed fields. The third parameter represents 
     * fields to ignore.
     * 
     * @param array $request
     * @param string|array|null $fields
     * @param string|array|null $ignored
     * @return array
     */
    public function committe($request, $fields = null, $ignored = null){

        if(!isset($request) ){ return []; }
        if(!is_array($request)){ return []; }

        $fields = (is_null($fields) ? [] : $fields);
        $fields = is_array($fields) ? $fields : [$fields];
        
        $ignored = (is_null($ignored) ? [] : $ignored);
        $ignored = is_array($ignored) ? $ignored : [$ignored];

        $response = [];

        /**
         * Field'lar gönderilmişse onlar kontrol edilir 
         * ve başka değerler de varsa onlara da null atanır.
         */
        if(!empty($fields)){
            foreach ($fields as $field) {

                if(isset($request[$field]) AND !in_array($field, $ignored)){
                    $response[$field] = $request[$field]; // içi boş ise null atanır değilse değer atanır.
                }

                if(!isset($request[$field]) AND !in_array($field, $ignored)){ 
                    $response[$field] = null;
                }
            }
        }

        if(empty($fields)){
            foreach ($request as $field => $value) {
                // İzin verilmeyen field'lardan veya boş ise null, değilse değer atanır.
                $response[$field] = (!in_array($field, $ignored) OR $value != '') ? $value : null;
            }
        }

        return $response;
    }

    /**
     * Normalizes a domain URL.
     *
     * @param string $url The URL to be normalized.
     * @return string The normalized URL.
     */
    public function normalizeDomain($url) {
        $urlParts = parse_url($url);

        // Start URL with "http: //" or "https: //" to check
        $url = (!isset($urlParts['scheme'])) ? "http://" . $url : $url;
        $urlParts = parse_url($url);

        // Check if the domain starts with 'www.'
        $domain = (substr($urlParts['host'], 0, 4) === 'www.') ? substr($urlParts['host'], 4) : $urlParts['host'];

        return $domain;
    }

    /**
     * Detecting an operating system
     * @return string
     */
    public function getOS(){
        $os = PHP_OS;
        switch (true) {
            case stristr($os, 'dar'): return 'Darwin';
            case stristr($os, 'win'): return 'Windows';
            case stristr($os, 'lin'): return 'Linux';
            default : return 'Unknown';
        }
    }
    /**
     * Detecting an client operating system
     * @return string
     */
    public function getClientOS(){
        $userAgent = $_SERVER['HTTP_USER_AGENT'];

        if (strpos($userAgent, 'Windows') !== false) {
            $os = 'Windows';
        } elseif (strpos($userAgent, 'Mac') !== false) {
            $os = 'Mac';
        } elseif (strpos($userAgent, 'Linux') !== false) {
            $os = 'Linux';
        } elseif (strpos($userAgent, 'Android') !== false) {
            $os = 'Android';
        } elseif (strpos($userAgent, 'iOS') !== false) {
            $os = 'iOS';
        } else {
            $os = 'Unknown';
        }
        return $os;
    }

    /**
     * Detecting an server software
     * @return string
     */
    public function getSoftware(){
        $software = $_SERVER['SERVER_SOFTWARE'];
        switch (true) {
            case stristr($software, 'apac'): return 'Apache';
            case stristr($software, 'micr'): return 'Microsoft-IIS';
            case stristr($software, 'lites'): return 'LiteSpeed';
            case stristr($software, 'nginx'): return 'Nginx';
            default : return 'Unknown';
        }
    }

    /**
     * Client browser identifier.
     */
    public function getBrowser($agent=null){
        $browserName = 'Unknown';
        $_SERVER['HTTP_USER_AGENT'] = empty($_SERVER['HTTP_USER_AGENT']) ? $browserName : $_SERVER['HTTP_USER_AGENT'];
        $agent = ($agent!=null) ? $agent : $_SERVER['HTTP_USER_AGENT']; 
        
        if(preg_match('/Edg/i',$agent)) 
        { 
            $browserName = "Edge";
        } 
        elseif(preg_match('/OPR/i',$agent)) 
        { 
            $browserName = "Opera"; 
        } 
        elseif(preg_match('/Firefox/i',$agent)) 
        { 
            $browserName = "Firefox"; 
        } 
        elseif(preg_match('/Chrome/i',$agent)) 
        { 
            $browserName = "Chrome"; 
        } 
        elseif(preg_match('/Safari/i',$agent)) 
        { 
            $browserName = "Safari"; 
        } 

        return $browserName;
    }

    /**
     * Routing manager.
     *
     * @param string $uri
     * @param mixed $file
     * @param mixed $cache
     * @param mixed $permissions
     * @return bool
     */
    public function route($uri, $file, $cache=null, $permissions=null){

        // Access blocking message            
        $permission_code = (!isset($permissions['error']['code'])) ? '401' : $permissions['error']['code'];
        $permission_message = (!isset($permissions['error']['message'])) ? 'You do not have the authority to display this route.' : $permissions['error']['message'];
        
        $permissions = (isset($permissions['params'])) ? $permissions['params'] : $permissions;        

        // Access directives are being created.
        $this->policyMaker();

        if(empty($file)){
            return false;
        }

        if($this->base_url != '/'){
            if(strstr(rawurldecode($_SERVER['REQUEST_URI']), $this->base_url)){
                $request = rawurldecode($_SERVER['REQUEST_URI']);
                for ($i=0; $i < mb_strlen($this->base_url); $i++) { 
                    $request[$i] = ' ';
                }
                $request = str_replace(' ', '', $request);
            }
        } else {
            $request = trim(rawurldecode($_SERVER['REQUEST_URI']), '/');
        }

        $fields     = array();

        if(!empty($uri)){

            $uriData = $this->wayMaker($uri);
            if(!empty($uriData['way'])){
                $uri = $uriData['way'];
                $this->page_uri = $uri;
            }
            if(!empty($uriData['params'])){
                $fields = $uriData['params'];
            }
        }

        if($uri == '/'){
            $uri = $this->base_url;
        }

        $params = array();

        if($_SERVER['REQUEST_METHOD'] != 'POST'){

            if(strstr($request, '/')){
                $params = explode('/', $request);
                $UriParams = explode('/', $uri);

                if(count($params) >= count($UriParams)){
                    for ($key = 0; count($UriParams) > $key; $key++){
                        unset($params[$key]);
                    }
                }

                $params = array_values($params);
            }

            $this->post = array();

            if(!empty($fields) AND !empty($params)){

                foreach ($fields as $key => $field) {

                    if(isset($params[$key])){

                        if(!empty($params[$key]) OR $params[$key] == '0'){
                            $this->post[$field] = $params[$key];
                        }

                    }
                }
            } else {
                $this->post = array_diff($params, array('', ' '));
            }
        } 

        if(!empty($request)){

            if(!empty($params)){
                $uri .= '/'.implode('/', $params);
            }

            if($request == $uri){
                $this->error_status = false;
                $this->page_current = $uri;
                $this->monitor['route'] = [
                    'uri'=>$this->page_uri,
                    'fields'=>$fields,
                    'params'=>$this->post
                ];
                if(!$this->permission_verification($permissions)){
                    $this->abort($permission_code, $permission_message);
                }
                $this->addLayer($file, $cache);
                exit();
            }

            $this->error_status = true;

        } else {
            if($uri == $this->base_url) {
                $this->error_status = false;
                $this->page_current = $uri;
                $this->monitor['route'] = [
                    'uri'=>$this->page_uri,
                    'fields'=>$fields,
                    'params'=>$this->post
                ];
                if(!$this->permission_verification($permissions)){
                    $this->abort($permission_code, $permission_message);
                }
                $this->addLayer($file, $cache);
                exit();
            }

        }
    
    }

    /**
     * File writer.
     *
     * @param array $data
     * @param string $filePath
     * @param string $delimiter
     * @return bool
     */
    public function write($data, $filePath, $delimiter = ':') {

        if(is_array($data)){
            $content    = implode($delimiter, $data);
        } else {
            $content    = $data;
        }

        if(isset($content)){
            $dirPath = $this->info($filePath, 'dirname');
            if(!empty($dirPath)){
                if(!is_dir($dirPath)){
                    mkdir($dirPath, 0777, true);
                }
            }
            if(!file_exists($filePath)){ touch($filePath); }
            if(file_exists($filePath)){ 
                $fileName        = fopen($filePath, "a+");
                fwrite($fileName, $content."\r\n");
                fclose($fileName);
            }

            return true;
        }

        return false;
    }

    /**
     * File uploader.
     *
     * @param array $files
     * @param string $path
     * @param bool $force
     * @return array
     */
    public function upload($files, $path, $force=false){

        $result = array();

        if(isset($files['name'])){ $files = array($files);}
        if(!is_dir($path)){ mkdir($path, 0777, true); }

        foreach ($files as $file) {

            #Path syntax correction for Windows.
            $tmp_name = str_replace('\\\\', '\\', $file['tmp_name']);
            $file['tmp_name'] = $tmp_name;

            $xtime      = gettimeofday();
            $xdat       = date('d-m-Y g:i:s').$xtime['usec'];
            $ext        = $this->info($file['name'], 'extension');
            if($force){
                $newpath    = $path.md5($xdat).'.'.$ext;
            } else {
                $options = array('unique'=>array('directory'=>$path));
                $newpath    = $path.$this->permalink($this->info($file['name'], 'filename'), $options).'.'.$ext;
                if(in_array($newpath, $result)){
                    $newpath    = $path.$this->permalink($this->info($file['name'], 'filename'), $options).'_'.$this->generateToken(8).'.'.$ext;
                }
            }

            if(move_uploaded_file($file['tmp_name'], $newpath)){
                $result[] = $newpath;
            }

        }

        return $result;
    }

     /**
     * It helps to copy files or files
     *
     * @param mixed $sources
     * @param mixed $destinations
     * @return array
     */
    public function duplicate($sources, $destinations){
        $result = [];

        $destinations = (isset($destinations)) ? (!is_array($destinations) ? [$destinations] : $destinations) : null;
        if(!is_null($destinations)) {
            foreach ($destinations as $destination) {                
                if(!is_dir($destination)){ mkdir($destination, 0777, true); }
            }
        }

        $sources = (isset($sources)) ? (!is_array($sources) ? [$sources] : $sources) : null;
        if(!is_null($sources)){
           foreach ($sources as $source) {
                $new_file_name      = $this->info($source, 'filename');
                $new_file_extension = $this->info($source, 'extension');
                $new_file           = $this->permalink($new_file_name).'.'.$new_file_extension;

                if(!is_null($destinations)){
                    foreach ($destinations as $destination) {
                        $new_file_path      =  $destination.'/'.$new_file;
                        
                        if(copy($source, $new_file_path)){
                            $result[] = $new_file_path;
                        }
                    }
                }
           } 
        }

        return $result;
    
    }

    /**
     * Content researcher.
     *
     * @param string $left
     * @param string $right
     * @param string $url
     * @param array $options
     * @return array|string
     */
    public function get_contents($left, $right, $url, $options=array()){

        $result = array();

        if($this->is_url($url)) {
            $ch = curl_init();
            curl_setopt($ch,CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HEADER, false);

            $defaultHeader = array(
                "Accept-Language" => isset($_SERVER["HTTP_ACCEPT_LANGUAGE"]) ? $_SERVER["HTTP_ACCEPT_LANGUAGE"] : '',
                "Connection" => "keep-alive"
            );

            if(!empty($options['authorization'])){
                curl_setopt($ch, CURLOPT_USERPWD, $options['authorization']['username'] . ":" . $options['authorization']['password']);
            }

            if(!empty($options['attachment'])){
                $options['post']['file'] = new CURLFile($options['attachment']);
                $defaultHeader['Content-Type'] = 'multipart/form-data';
            }

            if(isset($options['header'])){
                foreach ($options['header'] as $column => $value) {
                    $defaultHeader[] = $column.':'.$value;
                }
            }
            
            curl_setopt($ch, CURLOPT_HTTPHEADER, $defaultHeader);
            
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

            if(!empty($options['post'])){
                curl_setopt($ch, CURLOPT_POST, true);
                if(is_array($options['post']) AND !isset($options['attachment'])){
                    $options['post'] = http_build_query($options['post']);
                }

                curl_setopt($ch, CURLOPT_POSTFIELDS, $options['post']);
            }
            
            if(!empty($options['referer'])){
                curl_setopt($ch, CURLOPT_REFERER, $options['referer']);
            }

            if(!empty($options['proxy'])){
                if(!empty($options['proxy']['url'])){
                    curl_setopt($ch, CURLOPT_PROXY, $options['proxy']['url']);
                }

                if(!empty($options['proxy']['user'])){
                    curl_setopt($ch, CURLOPT_PROXYUSERPWD, $options['proxy']['user']);
                }

                if(!empty($options['proxy']['protocol'])){
                    curl_setopt($ch, CURLOPT_PROXYTYPE, $options['proxy']['protocol']); 
                }
            }

            if(!isset($options['header']['User-Agent'])){
                curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
            } 

            $data = curl_exec($ch);
            curl_close($ch);
            
            if(empty($data)){
                $data = file_get_contents($url);
            }
        } else {
            $data = $url;
        }


        if($left === '' AND $right === ''){
            return $data;
        }

        $content = str_replace(array("\n", "\r", "\t"), '', $data);

        if(preg_match_all('/'.preg_quote($left, '/').'(.*?)'.preg_quote($right, '/').'/i', $content, $result)){

            if(!empty($result)){
                return $result[1];
            } else {
                return $result;
            }
        }

        if(is_array($result)){
            if(empty($result[0]) AND empty($result[1])){
                return [];
            }
        }

        return $result;
    }

    /**
     * Permission verification
     * 
     * @param string|array|null $permissions
     * @return void
     */
    public function permission_verification($permissions = null){

        // It is made processable if the access permission to the route is defined
        if(isset($permissions)) { 
            $permissions = (!is_array($permissions))? array($permissions) : $permissions;
        } else {
            $permissions = null;
        }

        // If there are permissions assigned to the visitor, it is made processable
        if(isset($_SESSION['permissions'])){
            $_SESSION['permissions'] = (!is_array($_SESSION['permissions'])) ? array($_SESSION['permissions']) : $_SESSION['permissions'];
        } else {
            $_SESSION['permissions'] = null;
        }

        // Route and visitor's permissions are compared
        if(!is_null($permissions) AND !is_null($_SESSION['permissions'])){
            foreach($_SESSION['permissions'] as $permission){
                if(!in_array($permission, $permissions)){
                    return false;
                }
            }
        } 

        // If you don't have permits value, stop access
        if(!is_null($permissions) AND is_null($_SESSION['permissions'])){
            return false;
        }

        return true;
    }

    /**
     * Absolute path syntax
     *
     * @param string $path
     * @return string
     */
    public function get_absolute_path($path) {
        $path = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $path);
        $parts = array_filter(explode(DIRECTORY_SEPARATOR, $path), 'strlen');
        $absolutes = array();
        foreach ($parts as $part) {
            if ('.' == $part) continue;
            if ('..' == $part) {
                array_pop($absolutes);
            } else {
                $absolutes[] = $part;
            }
        }
        $outputdir = implode(DIRECTORY_SEPARATOR, $absolutes);
        if(strstr($outputdir, '\\')){
            $outputdir = str_replace('\\', '/', $outputdir);
        }
        return $outputdir;
    }

    /**
     *
     * Calculates the distance between two points, given their
     * latitude and longitude, and returns an array of values
     * of the most common distance units
     * {m, km, mi, ft, yd}
     *
     * @param float|int|string $lat1 Latitude of the first point
     * @param float|int|string $lon1 Longitude of the first point
     * @param float|int|string $lat2 Latitude of the second point
     * @param float|int|string $lon2 Longitude of the second point
     * @return mixed {bool|array}
     */
    public function distanceMeter($lat1, $lon1, $lat2, $lon2, $type = '') {

        $output = array();

        // koordinat değillerse false yanıtı döndürülür.
        if(!$this->is_coordinate($lat1, $lon1) OR !$this->is_coordinate($lat2, $lon2)){ return false; }

        // aynı koordinatlar belirtilmiş ise false yanıtı döndürülür.
        if (($lat1 == $lat2) AND ($lon1 == $lon2)) { return false; }

        // dereceden radyana dönüştürme işlemi
        $latFrom = deg2rad($lat1);
        $lonFrom = deg2rad($lon1);
        $latTo = deg2rad($lat2);
        $lonTo = deg2rad($lon2);

        $lonDelta = $lonTo - $lonFrom;
        $a = pow(cos($latTo) * sin($lonDelta), 2) +
            pow(cos($latFrom) * sin($latTo) - sin($latFrom) * cos($latTo) * cos($lonDelta), 2);
        $b = sin($latFrom) * sin($latTo) + cos($latFrom) * cos($latTo) * cos($lonDelta);

        $angle = atan2(sqrt($a), $b);

        $meters     = $angle * 6371000;
        $kilometers = $meters / 1000;
        $miles      = $meters * 0.00062137;
        $feet       = $meters * 3.2808399;
        $yards      = $meters * 1.0936;

        $data = array(
            'm'     =>  round($meters, 2),
            'km'    =>  round($kilometers, 2),
            'mi'    =>  round($miles, 2),
            'ft'    =>  round($feet, 2),
            'yd'    =>  round($yards, 2)
        );

        // eğer ölçü birimi boşsa tüm ölçülerle yanıt verilir
        if(empty($type)){
            return $data;
        }

        // eğer ölçü birimi string ise ve müsaade edilen bir ölçüyse diziye eklenir
        if(!is_array($type) AND in_array($type, array_keys($data))){
            $type = array($type);
        }

        // eğer ölçü birimi dizi değilse ve müsaade edilen bir ölçü değilse boş dizi geri döndürülür
        if(!is_array($type) AND !in_array($type, array_keys($data))){
            return $output;
        }

        // gönderilen tüm ölçü birimlerinin doğruluğu kontrol edilir
        foreach ($type as $name){
            if(!in_array($name, array_keys($data))){
                return $output;
            }
        }

        // gönderilen ölçü birimlerinin yanıtları hazırlanır
        foreach ($type as $name){
            $output[$name] = $data[$name];
        }

        // tek bir ölçü birimi gönderilmiş ise sadece onun değeri geri döndürülür
        if(count($type)==1){
            $name = implode('', $type);
            return $output[$name];
        }

        // birden çok ölçü birimi yanıtları geri döndürülür
        return $output;
    }

    /**
     * It is used to run Php codes.
     * 
     * @param string $code
     * @return void
     */
    public function evalContainer($code){

        if($this->is_htmlspecialchars($code)){
            $code = htmlspecialchars_decode($code);
        }
        
        ob_start();
        eval('?>'. $code);
        $output = ob_get_contents();
        ob_end_clean();
        echo $output;
    }

    /**
     * safeContainer
     * 
     * @param string $str
     * @param string|array|null $rule
     * @return string
     */
    public function safeContainer($str, $rule=null){

        if($this->is_htmlspecialchars($str)){
            $str = htmlspecialchars_decode($str);
        }

        $rules = array();
        $rulesList = array('inlinejs', 'inlinecss', 'tagjs', 'tagcss', 'iframe');

        if(!is_null($rule)){
            if(!is_array($rule)){
                $rules = array($rule);
            } else {
                foreach($rule as $rul){
                    if(in_array($rul, $rulesList)){
                        $rules[] = $rul;
                    }
                }
            }
        }

        if(!in_array('inlinejs', $rules)){
            $str = preg_replace('/(<.+?)(?<=\s)on[a-z]+\s*=\s*(?:([\'"])(?!\2).+?\2|(?:\S+?\(.*?\)(?=[\s>])))(.*?>)/i', "$1$3", $str);
        }

        if(!in_array('inlinecss', $rules)){
            $str = preg_replace('/(<[^>]*) style=("[^"]+"|\'[^\']+\')([^>]*>)/i', '$1$3', $str);
        }

        if(!in_array('tagjs', $rules)){
            $str = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $str);
            $str = preg_replace('/<link\b[^>]*.js(.*?)>/is', '', $str);
        }

        if(!in_array('tagcss', $rules)){
            $str = preg_replace('/<\s*style.+?<\s*\/\s*style.*?>/si', '', $str);
            $str = preg_replace('/<link\b[^>]*.css(.*?)>/is', '', $str);
        }

        if(!in_array('iframe', $rules)){
            $str = preg_replace('/<iframe.*?\/iframe>/i','', $str);
        }

        return $str;
    }

    /**
     * lifetime
     *
     * @param string $start_date
     * @param string|null $end_date
     * @return bool
     */
    public function lifetime($start_date, $end_date = null){
        if(!is_null($end_date)){
            $start_date = date_create($start_date);
            $end_date = date_create($end_date);
        } else {
            $end_date = date_create($start_date);
            $start_date = date_create($this->timestamp);
        }
        return ($start_date<$end_date);
    }

    /**
     * Morse encode
     * @param string $str
     * @param array|null $morseDictionary
     * @return string
     */
    public function morse_encode($str, $morseDictionary=array()){
 
        $output = '';    
        if(empty($morseDictionary)){
            $morseDictionary = $this->morsealphabet();
        } else {
            $morseDictionary = $this->morsealphabet($morseDictionary);
        }

        $str = mb_strtolower($str);        
        for ($i = 0; $i < mb_strlen($str); $i++) {
            $key = mb_substr($str, $i, 1);
            if(isset($morseDictionary[$key])){
                $output .= $morseDictionary[$key].' ';
            } else {
                $output .= '# ';
            }
        }
        return trim($output);
    }

    /**
     * Morse decode
     * @param string $morse
     * @param array|null $morseDictionary
     * @return string
     */
    public function morse_decode($morse, $morseDictionary=array()){
 
        $output = '';

        if($morse === ' '){
            return '/';
        }

        if(empty($morseDictionary)){
            $morseDictionary = array_flip($this->morsealphabet());
        } else {
            $morseDictionary = array_flip($this->morsealphabet($morseDictionary));
        }
        
        foreach (explode(' ', $morse) as $value) {
            if(isset($morseDictionary[$value])){
                $output .= $morseDictionary[$value];
            } else {
                $output .= '#';
            }
        }
        return $output;
    }   

    /**
     * String to Binary
     * @param string $string
     * @return int|string
     */
    public function stringToBinary($string){
        $characters = str_split($string);
 
        $binary = [];
        foreach ($characters as $character) {
            $data = unpack('H*', $character);
            $binary[] = base_convert($data[1], 16, 2);
        }
    
        return implode(' ', $binary);  
    }

    /**
     * Binary to String
     * @param int|string
     * @return string
     */
    public function binaryToString($binary){
        $binaries = explode(' ', $binary);
    
        $string = null;
        foreach ($binaries as $binary) {
            $string .= pack('H*', dechex(bindec($binary)));
        }
    
        return $string;    
    }

    /**
     * hexToBinary
     * @param string $hexstr
     * @return string
     */
    public function hexToBinary($hexstr) { 
		$n = strlen($hexstr); 
		$sbin="";   
		$i=0; 
		while($i<$n){       
			$a = substr($hexstr,$i,2);           
			$c = pack("H*",$a); 
			if ($i==0){
				$sbin=$c; 
			} else {
				$sbin.=$c;
			} 
		$i+=2; 
		} 
		return $sbin; 
	}

    /**
     * Converts a text string to its hexadecimal representation.
     *
     * @param string $text The text string to convert.
     * @return string The hexadecimal representation of the text string.
     */
    public function textToHex($text) {
        $utf8Text = mb_convert_encoding($text, 'UTF-8', 'auto');
        $hex = '';
        for ($i = 0; $i < strlen($utf8Text); $i++) {
            $code = dechex(ord($utf8Text[$i]));
            $hex .= str_pad($code, 2, '0', STR_PAD_LEFT);
        }
        return $hex;
    }

    /**
     * Converts a hexadecimal string to its text representation.
     *
     * @param string $hex The hexadecimal string to convert.
     * @return string The text representation of the hexadecimal string.
     */
    public function hexToText($hex) {
        $text = '';
        for ($i = 0; $i < strlen($hex); $i += 2) {
            $code = hexdec(substr($hex, $i, 2));
            $text .= chr($code);
        }
        return mb_convert_encoding($text, 'UTF-8', 'auto');
    }

    /**
     * siyakat_encode
     * @param string $siyakat
     * @param array $miftah
     * @return string
     */
    public function siyakat_encode($siyakat, $miftah){
        if(empty($miftah)){
            return '';
        }
        
        for ($i=0; $i < count($miftah); $i++) { 
            $siyakat = bin2hex($siyakat); // 1
            $siyakat = $this->morse_encode($siyakat, $miftah[$i]); // 2
        }
        return $siyakat;
    }

    /**
     * siyakat_decode
     * @param string $siyakat
     * @param array $miftah
     * @return string
     */
    public function siyakat_decode($siyakat, $miftah){
        if(empty($miftah)){
            return '';
        }
        $miftah = array_reverse($miftah);
        for ($i=0; $i < count($miftah); $i++) { 
            $siyakat = $this->morse_decode($siyakat, $miftah[$i]);
            $siyakat = $this->hexToBinary($siyakat);           
        }
        return $siyakat;
    }

    /**
     * Abort Page
     * @param string $code
     * @param string message
     * @return void
     */
    public function abort($code, $message){    
        $codelist = $this->addressCodeList();
        if(isset($codelist[$code])){
            header($_SERVER['SERVER_PROTOCOL']." ".$code." ".$codelist[$code]);
        }
        exit('<!DOCTYPE html><html lang="en"><head> <meta charset="utf-8"> <meta name="viewport" content="width=device-width, initial-scale=1"> <title>'.$code.'</title> <style>html, body{background-color: #fff; color: #636b6f; font-family: Arial, Helvetica, sans-serif; font-weight: 100; height: 100vh; margin: 0;}.full-height{height: 100vh;}.flex-center{align-items: center; display: flex; justify-content: center;}.position-ref{position: relative;}.code{border-right: 2px solid; font-size: 26px; padding: 0 15px 0 15px; text-align: center;}.message{font-size: 18px; text-align: center;}div.buttons{position:absolute;margin-top: 60px;}a{color: #333;font-size: 14px;text-decoration: underline;}a:hover{text-decoration:none;}</style></head><body><div class="flex-center position-ref full-height"> <div class="buttons"><a href="'.$this->page_back.'">Back to Page</a>&nbsp;|&nbsp;<a href="'.$this->base_url.'">Home</a></div><div class="code"> '.$code.' </div><div class="message" style="padding: 10px;"> '.$message.' </div></div></body></html>');
    }

    /**
     * Captcha
     * @param string|int $level
     * @param string|int $length
     * @param string|int $width
     * @param string|int $height
     * @return void
     */
    public function captcha($level=3, $length=8, $width=320, $height=60){
        $_SESSION['captcha'] = $this->generateToken($length);

        $im_width = 320;
        if(!is_null($width)){
            if(!strstr($width, '%', $width)){
                $im_width = $width;
                $width = $width.'px';
            }
        }

        $im_height = (is_null($height)) ? 60 : $height;
        $im_height = (strstr($height, '%', $height)) ? 60 : $height;

        $im=imagecreatetruecolor(ceil($im_width/2),ceil($im_height/2));
        $navy=imagecolorAllocate($im,0,0,0);
        
        $white=imagecolorallocate($im,255,255,255);
        $pixelColorList = array(
            imagecolorallocate($im, 125, 204, 130), // green
            imagecolorallocate($im, 0, 0, 255), // blue
            imagecolorallocate($im, 179, 179, 0), // yellow
        );

        $pixelColor = $pixelColorList[rand(0, count($pixelColorList)-1)];

        $text_width = imagefontwidth(5) * strlen($_SESSION['captcha']);
        $center = ceil($im_width / 4);
        $x = $center - ceil($text_width / 2);

        imagestring($im, 5, $x, ceil($im_height/8), $_SESSION['captcha'], $white);

        if($level != null){
            for($i=0;$i<$level*1000;$i++) {
                imagesetpixel($im,rand()%$im_width,rand()%$im_height,$pixelColor);
            }
        }

        ob_start();
        imagepng($im);
        $image_data = ob_get_contents();
        ob_end_clean();
        if(!empty($im)){
            imagedestroy($im);
        }

        ?>
        <div class="form-group" style="position:relative;">
            <label for="captcha"><img style="height:<?=$height;?>px; width:<?=$width;?>; object-fit: cover;image-rendering:high-quality;image-rendering: auto;image-rendering: crisp-edges;image-rendering: pixelated; position:absolute;" src="data:image/png;base64,<?=base64_encode($image_data);?>"></label>
        </div>
        <br><br>
        <input type="text" style="width:<?=$width;?>;" id="captcha" name="captcha" class="form-control">
        

        <?php

    }

    /**
     * Folder (including subfolders) and file eraser.
     * @param string $paths
     * @return bool
     */
    public function rm_r($paths) {
        if (is_array($paths)) {
            foreach ($paths as $path) {
                $this->rm_r($path);
            }
        } else {
            if (is_file($paths)) {                
                unlink($paths);                
                $dirPath = dirname($paths);
                while (is_dir($dirPath)) {
                    $files = scandir($dirPath);                    
                    if (count($files) === 2 && in_array('.', $files) && in_array('..', $files)) {
                        rmdir($dirPath);                        
                        $dirPath = dirname($dirPath);
                    } else {                        
                        break;
                    }
                }
            }
        }
    }

    /**
     * File and Folder searcher
     * @param string $dir
     * @param string $pattern
     * @param array $matches
     * @return array
     */
    public function ffsearch($dir, $pattern, $matches=array()){
        $dir_list = glob($dir . '*/');
        $pattern_match = glob($dir . $pattern);
    
        $matches = array_merge($matches, $pattern_match);
    
        foreach($dir_list as $directory){
            $matches = $this->ffsearch($directory, $pattern, $matches);
        }
    
        return $matches;
    }

    /**
     * json_encode
     * @param string $data
     * @return string
     */
    public function json_encode($data, $min = true, $header=false){
        if($header==true){ header('Content-Type: application/json; charset=utf-8'); }
        $data = ($min === true) ? json_encode($data, JSON_UNESCAPED_UNICODE) : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return str_replace(['&#039;', '&quot;', '&amp;'], ['\'', '\"', '&'], $data);
    }

    /**
     * json_decode
     * @param string $data
     * @return array
     */
    public function json_decode($data){
        return json_decode($data, true);
    }

    /**
     * saveAs
     * It is the method that saves the file differently.
     * @param string $file_path
     * @param string|null $filename
     * @param bool|null $download
     */
    public function saveAs($file_path, $filename=null, $download = true){

        $file_path = str_replace('..', '', $file_path);
        
        if($this->remoteFileSize($file_path) === false){
            $data       = $this->get_contents('', '', $file_path);
            $data       = ($data != $file_path) ? $data : file_get_contents($file_path);
        } else {
            $data       = $file_path;
        }

        $mime_type  = ($this->is_json($data)) ? 'application/json' : $this->mime_content_type($file_path);
        $new_filename   = (is_null($filename)) ? basename($file_path) : $filename;

        header('Access-Control-Allow-Origin: *');
        header("Content-type: ".$mime_type."; charset=utf-8");

        if($download === true){
            header('Content-Disposition: attachment; filename="'.$new_filename.'"');
        }

        if(!empty($file_path) AND is_null($filename) AND $download === false){
            $data = readfile($file_path);
        }
        echo $data;
    }

    /**
     * mime_content_type
     *
     * @param string $url
     * @return string
     */
    public function mime_content_type($url){

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $type = $finfo->buffer(file_get_contents($url));
        return $type;
    
    }

    /**
     * Function for displays such as disclaimers, advertisements, announcements
     * 
     * @param string $str
     * @param array|null $options
     * @return void
     */
    public function popup($str, $options=[]){
        $options['script'] = (isset($options['script'])) ? $options['script'] : '';
        $options['theme'] = (isset($options['theme'])) ? $options['theme'] : '';
        $options['theme'] = (in_array($options['theme'], ['white', 'black', 'red'])) ? $options['theme'] : 'red';
        $options['position'] = (isset($options['position'])) ? $options['position'] : '';
        $options['position'] = (in_array($options['position'], ['top', 'bottom', 'full'])) ? $options['position'] : 'bottom';
        
        if($options['theme'] == 'white'){
            $theme = [
                'div_popup'                 => 'background-color:#fbfbfb;color:#3c3c3c;',
                'div_popup_a'               => 'color:#cf5560;',
                'div_popup_a_hover'         => 'color:#e12536;',
                'btn_accept_popup'          => 'background-color:#cb1a3b;color:#fff;',
                'btn_accept_popup_hover'    => 'background-color:#d32445;color:#fff;',
                'btn_decline_popup'         => 'background-color:#e0dbdb;color:#000;',
                'btn_decline_popup_hover'   => 'background-color:#edebeb;color:#000;',
                'div_popup_counter'         => 'background-color: #424242cf; color:white;'
            ];

        }

        if($options['theme'] == 'black'){
            $theme = [
                'div_popup'                 => 'background-color: #000000;color: #e9e9e9;',
                'div_popup_a'               => 'color:#cf5560;',
                'div_popup_a_hover'         => 'color:#e12536;',
                'btn_accept_popup'          => 'background-color:#cb1a3b;color:#fff;',
                'btn_accept_popup_hover'    => 'background-color:#d32445;color:#fff;',
                'btn_decline_popup'         => 'background-color:#e0dbdb;color:#000;',
                'btn_decline_popup_hover'   => 'background-color:#edebeb;color:#000;',
                'div_popup_counter'         => 'background-color: #424242cf; color:white;'
            ];

        }

        if($options['theme'] == 'red'){
            $theme = [
                'div_popup'                 => 'background-color:#bf0326;color:#f8f8ff;',
                'div_popup_a'               => 'color:#df4c69;',
                'div_popup_a_hover'         => 'color:#e1687e;',
                'btn_accept_popup'          => 'background-color:#cb1a3b;color:#fff;',
                'btn_accept_popup_hover'    => 'background-color:#d32445;color:#fff;',
                'btn_decline_popup'         => 'background-color:#ddd;color:#000;',
                'btn_decline_popup_hover'   => 'background-color:#fff2f2;color:#000',
                'div_popup_counter'         => 'background-color: #424242c2; color:white;'
            ];
        }

        if($options['position'] == 'top'){
            $theme['div_popup_position'] = 'top:15px;';
        }
        if($options['position'] == 'bottom'){
            $theme['div_popup_position'] = 'bottom:15px;';
        }
        if($options['position'] == 'full'){
            $theme['div_popup_position'] = 'bottom:0px; top:0px; left:0px; right:0px;';
        }

        $acceptClick = (!empty($options['button']['true']['href'])) ? ' onclick="window.location.href=\''.$options['button']['true']['href'].'\';"' : '';
        $acceptText = (isset($options['button']['true']['text'])) ? $options['button']['true']['text'] : 'Yes';

        $declineClick = (!empty($options['button']['true']['href'])) ? ' onclick="window.location.href=\''.$options['button']['false']['href'].'\';"' : '';
        $declineText = (isset($options['button']['false']['text'])) ? $options['button']['false']['text'] : 'No, Thanks';

        $timeout = (isset($options['redirect']['timeout'])) ? $options['redirect']['timeout'] : 0;
        $url = (isset($options['redirect']['url'])) ? $options['redirect']['url'] : '';
        $again = (isset($options['again'])) ? $options['again'] : true;

    ?>
    
        <style>div.popup{display:none;position:fixed;z-index:9999999;height:auto;<?=$theme['div_popup_position'];?>border-radius:2px;margin:10px;box-shadow:0 0 15px 0 rgba(98,98,98,.75);padding:1rem;transition:1s;width:-webkit-fill-available;<?=$theme['div_popup'];?>}div.popup p{font-size:14px;font-weight:600;letter-spacing:.4px;line-height:22.9px;font-family:Arial,sans-serif}div.popup a{<?=$theme['div_popup_a'];?>font-weight:700;text-decoration:none}div.popup a:hover{ <?=$theme['div_popup_a_hover'];?>text-decoration: underline; transition:color .2s}button#popup_accept,button#popup_decline{border-width:0;font-size:14px;padding:7px 20px;cursor:pointer; float:left;}button#popup_accept:hover,button#popup_decline:hover{transition:background-color .5s}button#popup_accept{<?=$theme['btn_accept_popup'];?>}button#popup_accept:hover{<?=$theme['btn_accept_popup_hover'];?>}button#popup_decline{<?=$theme['btn_decline_popup'];?>}button#popup_decline:hover{<?=$theme['btn_decline_popup_hover'];?>} div.popup_counter{float: left;position: absolute;height: 40px;padding: 6px;margin: -1px;right: 8px; bottom:8px; width: 40px;border-radius: 40px;text-align: center;<?=$theme['div_popup_counter'];?>;font-size: 26px;line-height: 40px;}@media only screen and (max-width: 500px) { div.popup{width:95vw;}}</style>

        <div class="popup">
            
            <p><?=$str;?></p>
            <?php if(!empty($acceptText)){ ?>
                <button type="button" id="popup_accept"<?=$acceptClick;?>><?=$acceptText;?></button>
            <?php } ?>
            <?php if(!empty($declineText)){ ?>
                <button type="button" id="popup_decline"<?=$declineClick;?>><?=$declineText;?></button>
            <?php } ?>
            
            <?php if($timeout>0){ ?>
                <div class="popup_counter"></div>
            <?php } ?>
        
	    </div>
        <script>
            
            let e = document.getElementsByClassName("popup"), 
                t = document.getElementById("popup_accept"), 
                o = document.getElementById("popup_decline");
            
            window.addEventListener("load", function() {
                localStorage.getItem("popup"); 
                setTimeout(function() { 
                    if(!localStorage.getItem("popup")) {
                        e[0].style.display = "block";
                        <?php if($again == false){ ?>
                            localStorage.setItem("popup", false);
                        <?php } ?>
                    }

                    <?php if($again == false){ ?>
                        localStorage.setItem("popup", false);
                    <?php } ?>

                }, 500); 
                
                if(e!=null && t != null && o!=null){ 
                    t.addEventListener("click", function(t) { 
                        t.preventDefault(), 
                        localStorage.setItem("popup", true), 
                        e[0].style.display = "none"; 
                
                    <?php if(empty($acceptClick)){ ?>
                        window.location.replace('<?=$this->page_current;?>'); 
                    <?php } ?>
                    });
                    o.addEventListener("click", function(t) {
                        t.preventDefault(), 
                        localStorage.setItem("popup", false), 
                        e[0].style.display = "none"; 
                    });
                }

                let c = document.getElementsByClassName("popup_counter"); 
                if(!localStorage.getItem("popup")) {
                <?php if($timeout>0){ ?> 
                    let wait = 1000, 
                        delay = <?=$timeout;?>/wait;
                    let timeout = setInterval(function () { 
                        c[0].innerHTML = delay; 
                        if(delay<1){ 
                            e[0].style.display="none"; 
                            clearInterval(timeout); 
                            <?php if($url != ''){ ?> 
                                window.location.replace('<?=$url;?>'); 
                            <?php } ?> 
                            
                        } delay--; 
                    }, wait);
                
                <?php } ?>
                }
                
            });
        </script>
        <?php

        if(!empty($options['script'])){

            $AllCode = '';
            $JSFiles = '';

            $scriptPattern1 = $this->get_contents('<script>', '</script>', $options['script']);
            foreach ($scriptPattern1 as $index => $codeItem1) {
                if(!empty($codeItem1)){
                    $AllCode .= $codeItem1;
                    $options['script'] = str_replace('<script>'.$codeItem1.'</script>', '', $options['script']);
                }
            }

            $scriptPattern2 = $this->get_contents('<script type="application/javascript">', '</script>', $options['script']);
            foreach ($scriptPattern2 as $index => $codeItem2) {
                if(!empty($codeItem2)){
                    $AllCode .= $codeItem2;
                    $options['script'] = str_replace('<script type="application/javascript">'.$codeItem2.'</script>', '', $options['script']);
                }
            }

            if(stristr($options['script'], 'src=') OR stristr($options['script'], 'async')){

                $scriptPattern3 = $this->get_contents('<script', '</script>', $options['script']);
                foreach ($scriptPattern3 as $index => $codeItem3) {
                    if(!empty($codeItem3)){
                        $JSFiles .= '<script'.$codeItem3.'</script>';
                        $options['script'] = str_replace('<script'.$codeItem3.'</script>', '', $options['script']);
                    }
                }
            }

            if(!empty($JSFiles)){
                echo $JSFiles;
            }
            if(!empty($AllCode)){
                echo '<script>if(localStorage.getItem("popup") == \'true\'){'.$AllCode.'}</script>';
            }
            
        }

        
    }

    /**
     * It henlps to separate sentences
     * @param string $text
     * @param string|int $numSentences
     * @return string
     */
    public function managerSentence($text, $numSentences) {
        $text = htmlspecialchars(strip_tags(html_entity_decode($text)));
        $sentences = preg_split('/(?<=[.?!])\s+/', $text, $numSentences + 1, PREG_SPLIT_NO_EMPTY); // split the sentences in the text
        return implode(" ", array_slice($sentences, 0, $numSentences)); // concatenate the specified number of sentences and return as a string
    }
    
    /**
     * It converts to the specified format in the specified language
     * 
     * @param string $date_string
     * @param string $locale
     * @param null|string $format
     * @return string
     */
    function format_date($date_string, $locale = null, $format = null) { 
        
        $locale = (is_null($locale)) ? 'en_US' : $locale;
        $new_locale = ($locale != 'en_US') ? $locale : null;
        
        $dateInformations = $this->getDateLib($date_string);
        $newDateInformations = $this->getDateLib(null, 'en_US');
        if(!is_null($locale)){
            
            $oldMonths = explode('|', $dateInformations['month_names']);
            $newMonths = explode('|', $newDateInformations['month_names']);
            foreach ($oldMonths as $key => $oldMonth) {
                if(stristr($date_string, $oldMonth)){
                    $date_string = str_ireplace($oldMonth, $newMonths[$key], $date_string);
                }
            }
        }

        if(!is_null($format)){
            $dateInformations = $this->getDateLib($date_string);
            $oldMonths = explode('|', $dateInformations['month_names']);
            $newMonths = explode('|', $newDateInformations['month_names']);
            foreach ($oldMonths as $key => $oldMonth) {
                if(stristr($date_string, $oldMonth)){
                    $date_string = str_ireplace($oldMonth, $newMonths[$key], $date_string);
                    $date_string = date($format, strtotime($date_string));
                } else {
                    if (preg_match('/^[0-9 \-:\/]+$/', $date_string) && strtotime($date_string)) {
                        $date_string = date($format, strtotime($date_string));
                    }
                }
            }
        }
        
        if(!is_null($new_locale)){
            $dateInformations = $this->getDateLib($date_string);
            $newDateInformations = $this->getDateLib(null, $new_locale);
            $oldMonths = explode('|', $dateInformations['month_names']);
            $newMonths = explode('|', $newDateInformations['month_names']);
            foreach ($oldMonths as $key => $oldMonth) {
                if(stristr($date_string, $oldMonth)){
                    $date_string = str_ireplace($oldMonth, $newMonths[$key], $date_string);
                }
            }
        }

        $date_string = mb_convert_case($date_string, MB_CASE_TITLE, "UTF-8");
        return $date_string;
    }
    
    /**
     * Formats the given price into a standardized representation,
     * removing trailing zeros and ensuring consistent formatting.
     * 
     * @param mixed $price The price to be formatted, can be a string or numeric value.    
     * @return string The formatted price as a string.
     */
    public function formatPrice($price){

        // Convert the price to a floating-point number.
        $price = floatval($price);
        
        // Extract the decimal and integer parts of the price.
        $decimalPart = fmod($price, 1);
        $decimalPart = number_format($decimalPart, 2, '.', ''); // Round the decimal part to two decimal places.
        
        $integerPart = floor($price);
        
        // Format the integer part by adding commas for thousands separation.
        $formattedIntegerPart = number_format($integerPart);
        
        // Combine the formatted integer part and the decimal part to create the final formatted price.
        $formattedPrice = $formattedIntegerPart . '.' . substr($decimalPart, 2);
        
        // Return the formatted price as a string.
        return $formattedPrice;
    }   
      
}
