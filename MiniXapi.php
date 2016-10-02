<?php

require_once __DIR__."/src/utils/RewriteUtil.php";
require_once __DIR__."/src/utils/DatabaseException.php";
require_once __DIR__."/src/utils/StatementUtil.php";
require_once __DIR__."/ext/TinCanPHP/autoload.php";

/**
 * MiniXapi
 */
class MiniXapi {

	private $pdo;
	private $dsn;
	private $tablePrefix;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->tablePrefix="";
	}

	/**
	 * Serve the request.
	 */
	public function serve() {
		$components=RewriteUtil::getPathComponents();

		switch ($components[0]) {
			case "statements":
				break;
		}

		print_r($components);
	}

	/**
	 * Process the put statements request.
	 */
	private function processPostStatements($body) {
		$statement=TinCan\Statement::fromJSON($body);
		StatementUtil::formalize($statement);

		$statementObject=$statement->asVersion(TinCan\Version::latest());
		$statementEncoded=json_encode($statementObject,JSON_PRETTY_PRINT);

		$pdo=$this->getPdo();
		$q=$pdo->prepare(
			"INSERT INTO {$this->tablePrefix}statements ".
			"       (id, statement) ".
			"VALUES (:id, :statement) "
		);

		if (!$q)
			throw new DatabaseException($pdo->errorInfo());

		$r=$q->execute(array(
			"id"=>$statement->getId(),
			"statement"=>$statementEncoded
		));

		if (!$r)
			throw new DatabaseException($q->errorInfo());

		$indices=array();
		$indices[]=array(
			"type"=>"verb",
			"value"=>$statement->getVerb()->getId()
		);

		$indices[]=array(
			"type"=>"agent",
			"value"=>$statement->getActor()->getMbox()
		);

		$indices[]=array(
			"type"=>"activity",
			"value"=>$statement->getTarget()->getId()
		);

		$q=$pdo->prepare(
			"INSERT INTO {$this->tablePrefix}statements_index ".
			"       (type, value, statement_id) ".
			"VALUES (:type, :value, :statement_id)"
		);

		if (!$q)
			throw new DatabaseException($pdo->errorInfo());

		foreach ($indices as $index) {
			//print_r($index);
			$index["statement_id"]=$statement->getId();
			$r=$q->execute($index);
			if (!$r)
				throw new DatabaseException($q->errorInfo());
		}

		return array($statement->getId());
	}

	/**
	 * Get statements
	 */
	public function processGetStatements($query) {
		$pdo=$this->getPdo();

		/*$tables=array();
		$wheres=array();
		$params=array();
		$tableCount=0;

		if (isset($query["agent"])) {
			$tables[]="{$this->tablePrefix}statements_index as t_$tableCount"
			$wheres[]="t_$tableCount.statement_id=?"

			$tableCount++;
		}

		$tables[]="{$this->tablePrefix}statements as t_$tableCount";

		if (isset($query["statementId"])) {
			$wheres[]="t_$tableCount.statement_id=?";
			$params[]=$query["statementId"];
		}*/


		if (isset($query["statementId"])) {
			$q=$pdo->prepare(
				"SELECT * ".
				"FROM   {$this->tablePrefix}statements ".
				"WHERE  id=:statementId"
			);

			if (!$q)
				throw new DatabaseException($pdo->errorInfo());

			$r=$q->execute(array(
				"statementId"=>$query["statementId"]
			));

			if (!$r)
				throw new DatabaseException($q->errorInfo());
		}

		else {
			$q=$pdo->prepare(
				"SELECT * ".
				"FROM   {$this->tablePrefix}statements "
			);

			if (!$q)
				throw new DatabaseException($pdo->errorInfo());

			$r=$q->execute();

			if (!$r)
				throw new DatabaseException($q->errorInfo());
		}

		$res=array();
		foreach ($q as $row) {
			$res[]=json_decode($row["statement"],TRUE);
		}

		if (isset($query["statementId"]))
			return $res[0];

		return array(
			"statements"=>$res
		);
	}

	/**
	 * Process a request.
	 */
	public function processRequest($method, $url, $query=array(), $body="") {
		if ($method=="POST" && $url=="statements")
			return $this->processPostStatements($body);

		if ($method=="GET" && $url=="statements")
			return $this->processGetStatements($query);

		else throw new Exception("Unknown method: $method $url");
	}

	/**
	 * Set data service name.
	 */
	public function setDsn($dsn) {
		if ($this->pdo)
			throw new Exception("Can't set DSN, PDO already created");

		$this->dsn=$dsn;
	}

	/**
	 * Get pdo, create if not created already.
	 */
	private function getPdo() {
		if (!$this->pdo)
			$this->pdo=new PDO($this->dsn);

		return $this->pdo;
	}

	/**
	 * Install specified database
	 */
	public function install() {
		$pdo=$this->getPdo();

		if (!$pdo)
			throw new Exception("DSN not set for installation");

		$r=$pdo->query(
			"CREATE TABLE {$this->tablePrefix}statements ( ".
			"  id VARCHAR(255) NOT NULL PRIMARY KEY, ".
			"  statement TEXT ".
			")"
		);

		if (!$r)
			throw new DatabaseException($pdo->errorInfo());

		$r=$pdo->query(
			"CREATE TABLE {$this->tablePrefix}statements_index ( ".
			"  type VARCHAR(20) NOT NULL, ".
			"  value TEXT NOT NULL, ".
			"  statement_id VARCHAR(255) NOT NULL, ".
			"  PRIMARY KEY (type, value, statement_id) ".
			")"
		);

		if (!$r)
			throw new DatabaseException($pdo->errorInfo());
	}
}