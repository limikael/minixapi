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
	private $basicAuth;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->tablePrefix="";
	}

	/**
	 * Are we installed?
	 */
	public function isInstalled() {
		$pdo=$this->getPdo();

		$t=gettype($pdo->exec("SELECT COUNT(*) FROM {$this->tablePrefix}statements"));
		if ($t!="integer")
			return FALSE;

		$t=gettype($pdo->exec("SELECT COUNT(*) FROM {$this->tablePrefix}statements_index"));
		if ($t!="integer")
			return FALSE;

		return TRUE;
	}

	/**
	 * Set a table prefix.
	 */
	public function setTablePrefix($tablePrefix) {
		$this->tablePrefix=$tablePrefix;
	}

	/**
	 * Set username and password to use for basic auth.
	 */
	public function setBasicAuth($userColonPass) {
		$this->basicAuth=$userColonPass;
	}

	/**
	 * Serve the request.
	 */
	public function serve() {
		$auth=$_SERVER["PHP_AUTH_USER"].":".$_SERVER["PHP_AUTH_PW"];

		if ($this->basicAuth && $auth!=$this->basicAuth) {
		    header('WWW-Authenticate: Basic realm="MiniXapi"');
		    header('HTTP/1.0 401 Unauthorized');
			$res=array(
				"error"=>TRUE,
				"message"=>"Unauthorized"
			);
			echo json_encode($res,JSON_PRETTY_PRINT);
		    exit;
		}

		$components=RewriteUtil::getPathComponents();

		try {
			$res=$this->processRequest(
				$_SERVER['REQUEST_METHOD'],
				$components[0],
				$_REQUEST,
				file_get_contents('php://input')
			);
		}

		catch (Exception $e) {
			http_response_code(500);
			header('Content-Type: application/json');
			$res=array(
				"error"=>TRUE,
				"message"=>$e->getMessage()
			);
			echo json_encode($res,JSON_PRETTY_PRINT);
			return;
		}

		header('Content-Type: application/json');
		echo json_encode($res,JSON_PRETTY_PRINT);
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
			"       (statementId, statement) ".
			"VALUES (:statementId, :statement) "
		);

		if (!$q)
			throw new DatabaseException($pdo->errorInfo());

		$r=$q->execute(array(
			"statementId"=>$statement->getId(),
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

		$indices[]=array(
			"type"=>"relatedActivity",
			"value"=>$statement->getTarget()->getId()
		);

		$context=$statement->getContext();
		if ($context) {
			$contextActivities=$context->getContextActivities();
			$relatedActivities=array_merge(
				$contextActivities->getCategory(),
				$contextActivities->getGrouping(),
				$contextActivities->getParent(),
				$contextActivities->getOther()
			);

			foreach ($relatedActivities as $relatedActivity) {
				$indices[]=array(
					"type"=>"relatedActivity",
					"value"=>$relatedActivity->getId()
				);
			}
		}

		$qs=
			"INSERT INTO {$this->tablePrefix}statements_index ".
			"            (type, value, statementId) ".
			"VALUES ";

		$qa=array();
		$params=array();

		foreach ($indices as $index) {
			$qa[]="(?,?,?)";
			$params[]=$index["type"];
			$params[]=$index["value"];
			$params[]=$statement->getId();
		}

		$qs.=join(",",$qa);
		$q=$pdo->prepare($qs);
		if (!$q)
			throw new DatabaseException($pdo->errorInfo());

		$r=$q->execute($params);
		if (!$r)
			throw new DatabaseException($q->errorInfo());

		return array($statement->getId());
	}

	/**
	 * Get statements
	 */
	public function processGetStatements($query) {
		$pdo=$this->getPdo();

		$understood=array(
			"agent","verb","activity","statementId","related_activities"
		);

		foreach ($query as $k=>$v)
			if (!in_array($k,$understood))
				throw new Exception("Query parameter $k not understood at the moment.");

		if (isset($query["agent"])) {
			$decoded=json_decode($query["agent"],TRUE);
			if (!$decoded)
				throw new Exception("Unable to decode json for agent: ".$query["agent"]);

			if (!$decoded["mbox"])
				throw new Exception("Expected agent to have mbox");

			$query["agent"]=$decoded["mbox"];
		}


		$tables=array();
		$wheres=array();
		$params=array();
		$tableCount=0;

		$queryables=array(
			"agent"=>"agent",
			"verb"=>"verb",
			"activity"=>"activity",
		);

		if (isset($query["related_activities"]) && $query["related_activities"])
			$queryables["activity"]="relatedActivity";

		foreach ($queryables as $queryable=>$type) {
			if (isset($query[$queryable])) {
				$tables[]="{$this->tablePrefix}statements_index";
				$wheres[]="t_$tableCount.type=?";
				$params[]=$type;
				$wheres[]="t_$tableCount.value=?";
				$params[]=$query[$queryable];
				$tableCount++;
			}
		}

		$tables[]="{$this->tablePrefix}statements";
		if (isset($query["statementId"])) {
			$wheres[]="t_$tableCount.statementId=?";
			$params[]=$query["statementId"];
		}

		$prev=$tableCount;
		$tableCount++;

		$qs="SELECT DISTINCT t_$prev.statement FROM $tables[0] AS t_0 ";

		for ($i=1; $i<$tableCount; $i++) {
			$prev=$i-1;
			$qs.="JOIN $tables[$i] AS t_$i ON t_$prev.statementId=t_$i.statementId ";
		}

		if ($wheres)
			$qs.="WHERE ";

		$qs.=join(" AND ",$wheres);
		//echo $qs."\n"; print_r($params);

		$q=$pdo->prepare($qs);
		if (!$q)
			throw new DatabaseException($pdo->errorInfo());

		$r=$q->execute($params);
		if (!$r)
			throw new DatabaseException($q->errorInfo());

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
		if (!$this->pdo) {
			if (!$this->dsn)
				throw new Exception("No DSN or PDO set.");

			$this->pdo=new PDO($this->dsn);
		}

		return $this->pdo;
	}

	/**
	 * Install database tables.
	 */
	public function install() {
		$pdo=$this->getPdo();

		$r=$pdo->exec(
			"CREATE TABLE {$this->tablePrefix}statements ( ".
			"  statementId VARCHAR(255) NOT NULL PRIMARY KEY, ".
			"  statement TEXT ".
			")"
		);

		if ($r===FALSE)
			throw new DatabaseException($pdo->errorInfo());

		$r=$pdo->exec(
			"CREATE TABLE {$this->tablePrefix}statements_index ( ".
			"  type VARCHAR(20) NOT NULL, ".
			"  value TEXT NOT NULL, ".
			"  statementId VARCHAR(255) NOT NULL, ".
			"  PRIMARY KEY (type, value, statementId) ".
			")"
		);

		if ($r===FALSE)
			throw new DatabaseException($pdo->errorInfo());
	}

	/**
	 * Uninstall database tables.
	 */
	public function uninstall() {
		$pdo=$this->getPdo();

		$r=$pdo->exec(
			"DROP TABLE {$this->tablePrefix}statements"
		);

		if ($r===FALSE)
			throw new DatabaseException($pdo->errorInfo());

		$r=$pdo->exec(
			"DROP TABLE {$this->tablePrefix}statements_index"
		);

		if ($r===FALSE)
			throw new DatabaseException($pdo->errorInfo());
	}
}