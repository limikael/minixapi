<?php

require_once "PHPUnit/Framework/TestCase.php";
require_once __DIR__."/../../MiniXapi.php";

class MiniXapiTest extends PHPUnit_Framework_TestCase {

	function setUp() {
		if (file_exists(__DIR__."/../data/minixapitest.sqlite"))
			unlink(__DIR__."/../data/minixapitest.sqlite");

		if (!is_dir(__DIR__."/../data"))
			mkdir(__DIR__."/../data");

		$this->miniXapi=new MiniXapi();
		$this->miniXapi->setDsn("sqlite:".__DIR__."/../data/minixapitest.sqlite");
	}

	/**
	 * @expectedException Exception
	 */
	function testNoDsnInstall() {

		// We should trhow an error if the DSN is not set.
		$miniXapi=new MiniXapi();
		$miniXapi->install();
	}

	/**
	 * Test installation
	 */
	function testInstall() {
		$this->assertFalse(file_exists(__DIR__."/../data/minixapitest.sqlite"));
		$this->miniXapi->install();
		$this->assertTrue(file_exists(__DIR__."/../data/minixapitest.sqlite"));
	}

	/**
	 * Test put and get statements.
	 */
	function testGetPut() {
		$this->miniXapi->install();

		$statement=<<<__END__
{
  "actor": {
    "name": "Sally Glider",
    "mbox": "mailto:sally@example.com"
  },
  "verb": {
    "id": "http://adlnet.gov/expapi/verbs/experienced",
    "display": { "en-US": "experienced" }
  },
  "object": {
    "id": "http://example.com/activities/solo-hang-gliding",
    "definition": {
      "name": { "en-US": "Solo Hang Gliding" }
    }
  }
}
__END__;

		$res=$this->miniXapi->processRequest("POST","statements",array(),$statement);
		$this->assertEquals(sizeof($res),1);
		$this->assertEquals(strlen($res[0]),36);

		$res=$this->miniXapi->processRequest("GET","statements");
		$this->assertEquals(sizeof($res["statements"]),1);
		$this->assertEquals($res["statements"][0]["actor"]["name"],"Sally Glider");
		$id=$res["statements"][0]["id"];

		$res=$this->miniXapi->processRequest("GET","statements",array("statementId"=>$id));
		$this->assertEquals($res["actor"]["name"],"Sally Glider");
	}
}