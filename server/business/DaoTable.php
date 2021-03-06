<?php
/** DaoTable File
 * @package models 
 *  */
/**
 * DaoTable Class
 *
 * Class data layer for the Table class
 * 
 * @author https://github.com/maparrar/html5sync
 * @author maparrar <maparrar@gmail.com>
 * @package models
 */
class DaoTable{
    /** Database Object 
     * @var Database
     */
    protected $db;
    /** PDO handler object 
     * @var PDO
     */
    protected $handler;
    /**
     * Constructor: sets the database Object and the PDO handler
     * @param Database $db database object
     */
    function __construct($db){
        $this->db=$db;
    }
    
    //**************************************************************************
    //>>>>>>>>>>>>>>>>>>>>>>>>   DATABASE ACCESS   <<<<<<<<<<<<<<<<<<<<<<<<<<<<<
    //**************************************************************************
    /**
     * Carga una tabla de la base de datos
     * @param string $schema Nombre de la base de datos
     * @param string $tableName Nombre de la tabla que se quiere cargar
     * @param string $mode Modo de uso de la tabla: ('unlock': Para operaciones insert+read), ('lock': Para operaciones update+delete)
     * @param string $type Tipo de tabla, "table" si es una tabla completa, "query" si es una consulta, en este caso queda automáticamente en mode="lock" 
     * @param string $query Consulta si es de type="query"
     * @return Table
     */
    function loadTable($schema,$tableName,$mode,$type="table",$query=""){
        $table=new Table($tableName);
        $table->setMode($mode);
        $table->setType($type);
        if($table->getType()==="table"){
            $table->setColumns($this->loadColumns($schema,$table));
            $this->loadFKs($schema,$table);
        }elseif($table->getType()==="query"){
            $table->setQuery($query);
            $table->setColumns($this->loadQueryColumns($schema,$table));
        }
        return $table;
    }
    /**
     * Retorna la lista de campos de una Tabla
     * @param string $schema Nombre de la base de datos
     * @param Table $table Tabla con nombre en la base de datos
     * @return Column[] Lista de campos de la tabla
     */
    private function loadColumns($schema,$table){
        $list=array();
        $handler=$this->db->connect("all");
        if($this->db->getDriver()==="pgsql"){
            $sql="
                SELECT DISTINCT
                    a.attnum as order,
                    a.attname as name,
                    format_type(a.atttypid, a.atttypmod) as type,
                    a.attnotnull as notnull, 
                    com.description as comment,
                    coalesce(i.indisprimary,false) as key,
                    def.adsrc as default
                FROM pg_attribute a 
                JOIN pg_class pgc ON pgc.oid = a.attrelid
                LEFT JOIN pg_index i ON 
                    (pgc.oid = i.indrelid AND i.indkey[0] = a.attnum)
                LEFT JOIN pg_description com on 
                    (pgc.oid = com.objoid AND a.attnum = com.objsubid)
                LEFT JOIN pg_attrdef def ON 
                    (a.attrelid = def.adrelid AND a.attnum = def.adnum)
                WHERE a.attnum > 0 AND pgc.oid = a.attrelid
                AND pg_table_is_visible(pgc.oid)
                AND NOT a.attisdropped
                AND pgc.relname = '".$table->getName()."' 
                ORDER BY a.attnum;";
        }elseif($this->db->getDriver()==="mysql"){
            $sql='
                SELECT 
                    ORDINAL_POSITION AS `order`,
                    COLUMN_NAME AS `name`,
                    DATA_TYPE AS `type`,
                    IS_NULLABLE AS `nullable`,
                    EXTRA AS `autoincrement`,
                    COLUMN_KEY AS `key` 
                FROM 
                    information_schema.columns 
                WHERE 
                    TABLE_SCHEMA="'.$schema.'" AND 
                    TABLE_NAME = "'.$table->getName().'"
                ';
        }
        $stmt = $handler->prepare($sql);
        if ($stmt->execute()) {
            while ($row = $stmt->fetch()){
                $column=new Column($row["name"],$row["type"]);
                $column->setOrder($row["order"]);
                if($row["key"]==="t"||$row["key"]==="PRI"||$row["key"]===true){
                    $column->setPk(true);
                }
                if(strpos($row["type"],"int")!==false||strpos($row["type"],"numeric")!==false){
                    $column->setType("int");
                }elseif(strpos($row["type"],"double")!==false||strpos($row["type"],"real")!==false){
                    $column->setType("double");
                }elseif(strpos($row["type"],"char")!==false){
                    $column->setType("varchar");
                }elseif(strpos($row["type"],"timestamp")!==false||strpos($row["type"],"date")!==false){
                    $column->setType("datetime");
                }else{
                    $column->setType($row["type"]);
                }
                if($this->db->getDriver()==="pgsql"){
                    if($row["notnull"]===true){
                        $column->setNotNull(true);
                    }
                    if(strpos($row["default"],"nextval")!==false){
                        $column->setAutoIncrement(true);
                    }
                }elseif($this->db->getDriver()==="mysql"){
                    if($row["nullable"]==="NO"){
                        $column->setNotNull(true);
                    }
                    if(strpos($row["autoincrement"],"auto_increment")!==false){
                        $column->setAutoIncrement(true);
                    }
                }
                if($row["key"]==="t"||$row["key"]==="PRI"||$row["key"]===true){
                    $column->setPk(true);
                }
                array_push($list,$column);
            }
        }else{
            $error=$stmt->errorInfo();
            error_log("[".__FILE__.":".__LINE__."]"."html5sync: ".$error[2]);
        }
        return $list;
    }
    /**
     * Retorna la lista de campos de una Tabla de tipo="query"
     * @param string $schema Nombre de la base de datos
     * @param Table $table Tabla con nombre en la base de datos
     * @return Column[] Lista de campos de la tabla
     */
    private function loadQueryColumns($schema,$table){
        $list=array();
        $handler=$this->db->connect("all");
        //Se procesa la consulta para retornar solo los títulos
        $sql=str_replace(";","",$table->getQuery());
        $sql.=" LIMIT 1 ";
        $stmt = $handler->prepare($sql);
        $order=1;
        if ($stmt->execute()) {
            $row = $stmt->fetchAll(PDO::FETCH_ASSOC)[0];
            foreach ($row as $colName => $colValue) {
                //Se detecta el tipo de datos
                $type="varchar";
                if (is_numeric($colValue)){
                    if(strpos($colValue,'.')!==false) {
                        $type="double";
                    }else{
                        $type="int";
                    }
                }
                $column=new Column($colName,$type);
                $column->setOrder($order);
                $column->setType($type);
                array_push($list,$column);
                $order++;
            }
        }else{
            $error=$stmt->errorInfo();
            error_log("[".__FILE__.":".__LINE__."]"."html5sync: ".$error[2]);
        }
        return $list;
    }
    /**
     * Carga la tabla con los datos de las FK
     * @param string $schema Nombre de la base de datos
     * @param Table $table Tabla con nombre en la base de datos
     * @return Table byREF: Tabla con la FK's cargadas
     */
    private function loadFKs($schema,$table){
        $handler=$this->db->connect("all");
        if($this->db->getDriver()==="pgsql"){
            $sql="
                SELECT
                    tc.constraint_name, tc.table_name, kcu.column_name, 
                    ccu.table_name AS foreign_table_name,
                    ccu.column_name AS foreign_column_name 
                FROM 
                    information_schema.table_constraints AS tc 
                    JOIN information_schema.key_column_usage AS kcu
                      ON tc.constraint_name = kcu.constraint_name
                    JOIN information_schema.constraint_column_usage AS ccu
                      ON ccu.constraint_name = tc.constraint_name
                WHERE constraint_type = 'FOREIGN KEY' AND tc.table_name='".$table->getName()."';
                ";
        }elseif($this->db->getDriver()==="mysql"){
            $sql='
                SELECT 
                    column_name,
                    referenced_table_name AS foreign_table_name,
                    referenced_column_name AS foreign_column_name 
                FROM 
                    information_schema.key_column_usage 
                WHERE 
                    referenced_table_name IS NOT NULL AND 
                    table_name="'.$table->getName().'"
                ';
        }
        $stmt = $handler->prepare($sql);
        if ($stmt->execute()) {
            while ($row = $stmt->fetch()){
                foreach ($table->getColumns() as $column) {
                    if($row["column_name"]===$column->getName()){
                        $column->setFk(true);
                        $column->setFkTable($row["foreign_table_name"]);
                        $column->setFkColumn($row["foreign_column_name"]);
                    }
                }
            }
        }else{
            $error=$stmt->errorInfo();
            error_log("[".__FILE__.":".__LINE__."]"."html5sync: ".$error[2]);
        }
    }
    /**
     * Retorna la cantidad de filas de una tabla.
     * @param string $table Nombre de la tabla que se quiere verificar
     * @return int Cantidad de filas que tiene una tabla
     */
    public function countRows($table){
        $total=0;
        $handler=$this->db->connect("all");
        $sql="SELECT COUNT(*) AS total FROM ".$table->getName();
        $stmt = $handler->prepare($sql);
        if ($stmt->execute()) {
            $row=$stmt->fetch();
            $total=intval($row["total"]);
        }else{
            $error=$stmt->errorInfo();
            error_log("[".__FILE__.":".__LINE__."]"."html5sync: ".$error[2]);
        }
        return $total;
    }
    /**
     * Retorna la cantidad de filas de una tabla de tipo "query".
     * @param string $table Nombre de la tabla que se quiere verificar
     * @return int Cantidad de filas que tiene una consulta
     */
    public function countQueryRows($table){
        $total=0;
        $handler=$this->db->connect("all");
        //Se procesa la consulta para retornar solo los títulos
        $sql=$table->getQuery();
        $stmt = $handler->prepare($sql);
        if ($stmt->execute()) {
            $total=$stmt->rowCount();
        }else{
            $error=$stmt->errorInfo();
            error_log("[".__FILE__.":".__LINE__."]"."html5sync: ".$error[2]);
        }
        return $total;
    }
    /**
     * Retorna un array con los datos de la tabla (un array por registro)
     * @param Table $table Tabla con nombre y lista de campos
     * @param int $initialRow [optional] Indica la fila desde la quedebe cargar los registros
     * @param int $maxRows [optional] Máxima cantidad de registros a cargar
     * @return array[] Array de arrays con los registros de la tabla
     */
    function getRows($table,$initialRow=0,$maxRows=1000){
        $list=array();
        $handler=$this->db->connect("all");
        
        if($table->getType()==="table"){
            if($this->db->getDriver()==="pgsql"){
                $sql="SELECT * FROM ".$table->getName()." LIMIT ".$maxRows." OFFSET ".$initialRow;
            }elseif($this->db->getDriver()==="mysql"){
                $sql="SELECT * FROM ".$table->getName()." LIMIT ".$initialRow.",".$maxRows;
            }
        }elseif($table->getType()==="query"){
            $sql=$table->getQuery();
        }
        $stmt = $handler->prepare($sql);
        if ($stmt->execute()) {
            while ($row = $stmt->fetch()){
                $register=array();
                foreach ($table->getColumns() as $column) {
                    array_push($register,$row[$column->getName()]);
                }
                array_push($list,$register);
            }
        }else{
            $error=$stmt->errorInfo();
            error_log("[".__FILE__.":".__LINE__."]"."html5sync: ".$error[2]);
        }
        return $list;
    }
    
    //**************************************************************************
    //>>>>>>>>>>>>>>>>>>>>>   DATABASE PREPARATION   <<<<<<<<<<<<<<<<<<<<<<<<<<<
    //**************************************************************************
    /**
     * Crea la tabla de transacciones en la base de datos BusinessDB.
     */
    public function createTransactionsTable(){
        $handler=$this->db->connect("all");
        if($this->db->getDriver()==="pgsql"){
            $handler->query('CREATE TABLE IF NOT EXISTS html5sync (html5sync_id SERIAL PRIMARY KEY,html5sync_table varchar(40) NOT NULL,html5sync_key varchar(20) NOT NULL,html5sync_date timestamp DEFAULT NULL,html5sync_transaction varchar(20) NOT NULL)');
        }elseif($this->db->getDriver()==="mysql"){
            $handler->query('CREATE TABLE IF NOT EXISTS html5sync (html5sync_id INT NOT NULL AUTO_INCREMENT,html5sync_table varchar(40) NOT NULL,html5sync_key varchar(20) NOT NULL,html5sync_date datetime DEFAULT NULL, html5sync_transaction varchar(40) NOT NULL, PRIMARY KEY (html5sync_id))');
        }
    }
    /**
     * Crea el procedimiento almacenado para el trigger de la tabla de transacciones
     * Actualmente solo aplica para bases de datos PostgreSQL. Para bases de datos
     * MySQL el procedimiento se inserta directamente en el Trigger
     * @param Table $table Tabla sobre la que se crearán los procedimientos
     */
    public function createTransactionsProcedures($table){
        $handler=$this->db->connect("all");
        if($this->db->getDriver()==="pgsql"){
            $pk=$table->getPk();
            $sql="CREATE OR REPLACE FUNCTION html5sync_proc_".$table->getName()."() RETURNS TRIGGER AS $$ ".
                "DECLARE ".
                        "id text;".
                "BEGIN  ".
                        "IF TG_OP = 'INSERT' THEN ".
                            "id := NEW.".$pk->getName()."; ".
                        "ELSE ".
                            "id := OLD.".$pk->getName()."; ".
                        "END IF; ".
                        "INSERT INTO html5sync  ".
                                "(html5sync_table,html5sync_key,html5sync_date,html5sync_transaction)  ".
                        "VALUES ".
                                "(TG_TABLE_NAME,id,current_timestamp,TG_OP);  ".
                "IF TG_OP = 'DELETE' THEN ".
                    "RETURN OLD;  ".
                "ELSE ".
                    "RETURN NEW;  ".
                "END IF; ".
                "END; $$ LANGUAGE plpgsql; ";
            $handler->query($sql);
        }
    }
    /**
     * Crea el conjunto de triggers de la tabla de transacciones
     * @param Table $table Tabla sobre la que se crearán los triggers
     */
    public function createTransactionsTriggers($table){
        $handler=$this->db->connect("all");
        if($this->db->getDriver()==="pgsql"){
            $handler->query("CREATE TRIGGER html5sync_trig_insert_".$table->getName()." BEFORE INSERT ON ".$table->getName()." FOR EACH ROW EXECUTE PROCEDURE html5sync_proc_".$table->getName()."();");
            $handler->query("CREATE TRIGGER html5sync_trig_update_".$table->getName()." BEFORE UPDATE ON ".$table->getName()." FOR EACH ROW EXECUTE PROCEDURE html5sync_proc_".$table->getName()."();");
            $handler->query("CREATE TRIGGER html5sync_trig_delete_".$table->getName()." BEFORE DELETE ON ".$table->getName()." FOR EACH ROW EXECUTE PROCEDURE html5sync_proc_".$table->getName()."();");
        }elseif($this->db->getDriver()==="mysql"){
            $pk=$table->getPk();
            //Se inserta el trigger para cada operación si la columna tiene PK
            if($pk){
                $handler->query('CREATE TRIGGER html5sync_trig_insert_'.$table->getName().' AFTER INSERT ON '.$table->getName().' FOR EACH ROW BEGIN DECLARE id TEXT; SELECT '.$pk->getName().' FROM '.$table->getName().' WHERE '.$pk->getName().'=NEW.'.$pk->getName().' INTO id; INSERT INTO html5sync (html5sync_table,html5sync_key,html5sync_date,html5sync_transaction) VALUES("'.$table->getName().'",id,NOW(),"INSERT"); END;');
                $handler->query('CREATE TRIGGER html5sync_trig_update_'.$table->getName().' BEFORE UPDATE ON '.$table->getName().' FOR EACH ROW BEGIN DECLARE id TEXT; SELECT '.$pk->getName().' FROM '.$table->getName().' WHERE '.$pk->getName().'=OLD.'.$pk->getName().' INTO id; INSERT INTO html5sync (html5sync_table,html5sync_key,html5sync_date,html5sync_transaction) VALUES("'.$table->getName().'",id,NOW(),"UPDATE"); END;');
                $handler->query('CREATE TRIGGER html5sync_trig_delete_'.$table->getName().' BEFORE DELETE ON '.$table->getName().' FOR EACH ROW BEGIN DECLARE id TEXT; SELECT '.$pk->getName().' FROM '.$table->getName().' WHERE '.$pk->getName().'=OLD.'.$pk->getName().' INTO id; INSERT INTO html5sync (html5sync_table,html5sync_key,html5sync_date,html5sync_transaction) VALUES("'.$table->getName().'",id,NOW(),"DELETE"); END;');
            }
        }
    }
    //**************************************************************************
    //>>>>>>>>>>>>>>>>>>>>>>>   DATABASE QUERIES   <<<<<<<<<<<<<<<<<<<<<<<<<<<<<
    //**************************************************************************
    /**
     * Retorna la lista de transacciones filtradas, es decir, si hay un delete 
     * después de un update en un mismo registro, retorna solo el delete.
     * @param DateTime $lastUpdate Objeto de fecha con la última fecha de actualización
     * @return 
     */
    public function getLastTransactions($lastUpdate){
        $list=array();
        $handler=$this->db->connect("all");
        $stmt = $handler->prepare("
            SELECT temp.*
            FROM html5sync temp
            INNER JOIN
                (SELECT html5sync_table,html5sync_key, MAX(html5sync_date) AS MaxDateTime
                FROM html5sync
                WHERE html5sync_date>:lastUpdate GROUP BY html5sync_table,html5sync_key) tempGroup 
            ON temp.html5sync_table = tempGroup.html5sync_table 
            AND temp.html5sync_date = tempGroup.MaxDateTime ORDER BY temp.html5sync_date;"
        );
        $date=$lastUpdate->format('Y-m-d H:i:s.u');
        $stmt->bindParam(':lastUpdate',$date);
        if ($stmt->execute()) {
            while ($row = $stmt->fetch()){
                $transaction=new Transaction($row["html5sync_id"],$row["html5sync_transaction"],$row["html5sync_table"],$row["html5sync_key"],$row["html5sync_date"]);
                array_push($list,$transaction);
            }
        }else{
            $error=$stmt->errorInfo();
            error_log("[".__FILE__.":".__LINE__."]"."html5sync: ".$error[2]);
        }
        return $list;
    }
    /**
     * Retorna un registro de una tabla desde la base de datos del negocio BusinessDB
     * @param Table $table Objeto de tipo tabla para retornar el registro
     * @param mixed $key Clave del registro que se quiere cargar
     */
    public function getRowOfTable($table,$key){
        $register = false;
        $handler=$this->db->connect("all");
        $pk=$table->getPk();
        $stmt = $handler->prepare("SELECT * FROM ".$table->getName()." WHERE ".$pk->getName()."=:key");
        $stmt->bindParam(':key',$key);
        if ($stmt->execute()) {
            $row=$stmt->fetch(PDO::FETCH_ASSOC);
            if($row){
                $register=$row;
            }
        }else{
            $error=$stmt->errorInfo();
            error_log("[".__FILE__.":".__LINE__."]"."html5sync: ".$error[2]);
        }
        return $register;
    }
    //**************************************************************************
    //>>>>>>>>>>>>>>>>>>>>>   TRANSACTIONS QUERIES   <<<<<<<<<<<<<<<<<<<<<<<<<<<
    //**************************************************************************
    /**
     * Recibe un objeto de tipo tabla y un array asociativo con los datos a almacenar
     * @param Table $table Objeto de tipo tabla
     * @param array $register Array asociativo saneado para almacenar
     * @return bool False si no hay error, String con el error
     */
    public function addRegister($table,$register){
        $error=false;
        $columns="";
        $values="";
        foreach ($register as $column => $value) {
            $tableColumn=$table->getColumn($column);
            if($tableColumn){
                $columns.=$column.',';
                $values.=':'.$column.',';
            }else{
                $error="Wrong column specification";
                break;
            }
        }
        if(!$error){
            //Remove the last comma
            $columns=substr($columns,0,-1);
            $values=substr($values,0,-1);
            $sql='INSERT INTO '.$table->getName().' ('.$columns.') VALUES ('.$values.')';
            $handler=$this->db->connect("all");
            $stmt = $handler->prepare($sql);
            foreach ($register as $column => &$value) {
                $stmt->bindParam(':'.$column,$value);
            }
            if (!$stmt->execute()) {
                $dberror=$stmt->errorInfo();
                $error=$dberror[2];
                error_log("[".__FILE__.":".__LINE__."]"."html5sync: ".$error);
            }
        }
        return $error;
    }
    /**
     * Recibe un objeto de tipo tabla y un array asociativo con los datos a actualizar
     * @param Table $table Objeto de tipo tabla
     * @param array $register Array asociativo saneado para actualizar, debe tener un valor existente de PK
     * @return bool False si no hay error, String con el error
     */
    public function updateRegister($table,$register){
        $error=false;
        $columns="";
        $pkColumn=false;
        $pkValue=false;
        foreach ($register as $column => $value) {
            $tableColumn=$table->getColumn($column);
            if($tableColumn){
                if($tableColumn->isPK()){
                    $pkColumn=$column;
                    $pkValue=$value;
                }else{
                    $columns.=$column.'=:'.$column.',';
                }
            }else{
                $error="Wrong column specification";
                break;
            }
        }
        if(!$error){
            //Remove the last comma
            $columns=substr($columns,0,-1);
            $sql='UPDATE '.$table->getName().' SET '.$columns.' WHERE '.$pkColumn.'=:'.$pkColumn.' ';
            $handler=$this->db->connect("all");
            $stmt = $handler->prepare($sql);
            foreach ($register as $column => &$value) {
                $stmt->bindParam(':'.$column,$value);
            }
            $stmt->bindParam(':'.$pkColumn,$pkValue);
            if (!$stmt->execute()) {
                $dberror=$stmt->errorInfo();
                $error=$dberror[2];
                error_log("[".__FILE__.":".__LINE__."]"."html5sync: ".$error);
            }
        }
        return $error;
    }
    /**
     * Recibe un objeto de tipo tabla y un array asociativo con los datos del registro a eliminar
     * @param Table $table Objeto de tipo tabla
     * @param array $register Array asociativo saneado para eliminar, debe tener un valor existente de PK
     * @return bool False si no hay error, String con el error
     */
    public function deleteRegister($table,$register){
        $error=false;
        $columns="";
        $pkColumn=false;
        $pkValue=false;
        $pk=$table->getPk();
        $sql='DELETE FROM '.$table->getName().' WHERE '.$pk->getName().'=:'.$pk->getName().' ';
        $handler=$this->db->connect("all");
        $stmt = $handler->prepare($sql);
        $stmt->bindParam(':'.$pk->getName(),$register[$pk->getName()]);
        if (!$stmt->execute()) {
            $dberror=$stmt->errorInfo();
            $error=$dberror[2];
            error_log("[".__FILE__.":".__LINE__."]"."html5sync: ".$error);
        }
        return $error;
    }
}