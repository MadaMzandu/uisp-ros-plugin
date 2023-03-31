ATTACH 'data/tmp.db' as tmp;
DROP TABLE IF EXISTS tmp."services";
CREATE TABLE IF NOT EXISTS tmp."services" (
        "id"       INT,
        "device"   INT,
        "address"  TEXT,
        "prefix6"   TEXT,
        "clientId" INT,
        "planId"   INT,
        "status"   INT,
        "last"   TEXT,
        "created"  TEXT
);
DROP TABLE IF EXISTS tmp."devices";
CREATE TABLE  IF NOT EXISTS tmp."devices" (
        "id"    INTEGER NOT NULL,
        "name"  TEXT,
        "ip"    TEXT,
        "type"  TEXT,
        "user"  TEXT,
        "password"      TEXT,
        "dbname"        TEXT,
        "pool"  TEXT,
        "pool6"  TEXT,
        "pfxLength" INT,
        "last"  TEXT,
        "created"       TEXT,
        PRIMARY KEY("id" AUTOINCREMENT)
        );
DROP TABLE IF EXISTS tmp."plans";
CREATE TABLE IF NOT EXISTS tmp."plans" (
        "id"    INTEGER NOT NULL,
        "ratio" INTEGER,
        "priority" INTEGER,
        "limitUpload" INTEGER ,
        "limitDownload" INTEGER ,
        "burstUpload" INTEGER ,
        "burstDownload" INTEGER ,
        "threshUpload" INTEGER,
        "threshDownload" INTEGER ,
        "timeUpload" FLOAT,
        "timeDownload" FLOAT,
        "last"  TEXT,
        "created"       TEXT,
        PRIMARY KEY("id")
);
DROP TABLE IF EXISTS tmp."config" ;
CREATE TABLE IF NOT EXISTS tmp."config" (
        "key"   TEXT NOT NULL,
        "value" TEXT,
        "last"  TEXT,
        "created"       TEXT,
        PRIMARY KEY("key")
);
INSERT INTO tmp.config ("key","value","last","created") SELECT "key","value","last","created" FROM config;
INSERT INTO tmp.services (id,device,address,prefix6,clientId,planId,status,last,created)
SELECT id,device,address,prefix6,clientId,planId,status,"last",created FROM services ;
INSERT INTO tmp.devices (id,name,ip,type,user,password,dbname,pool,pool6,pfxLength,"last",created)
SELECT id,name,ip,type,user,password,dbname,pool,pool6,pfxLength,"last",created FROM devices ;
INSERT INTO tmp.plans ("id","ratio","last","created") SELECT "id","ratio","last","created" FROM plans ;

CREATE INDEX tmp."xsvc_address" ON "services" ("address");
CREATE INDEX tmp."xsvc_prefix6" ON "services" ("prefix6");
CREATE INDEX tmp."xsvc_device" ON "services" ("device");
CREATE INDEX tmp."xsvc_planId" ON "services" ("planId");
CREATE INDEX tmp."xdev_name" ON "devices" ("name" COLLATE nocase);
