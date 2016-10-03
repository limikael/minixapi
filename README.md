# minixapi

An embeddable xAPI learning record store.

* [Introduction](#introduction)
* [Usage as a REST endpoint](#usage-as-a-rest-endpoint)
* [Usage as a library](#usage-as-a-library)

## Introduction
MiniXapi is an xAPI LRS. It has no where near full xAPI compliance, it might get there some day, but it is not seen as a goal, it will grow on an need basis. It is common for xAPI record stores to store their data in a NoSQL database, such as MongoDB. MiniXapi stores it's data in a standard PDO relational database. It was designed this way in order to fit into a traditional web applications and environments, such as WordPress. It can expose an xAPI REST endpoint. It can also be used as a library, in order to be embedded into other web applications.

## Usage as a REST endpoint
