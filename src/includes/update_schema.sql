ATTACH 'data/tmp.db' as tmp;
CREATE TABLE IF NOT EXISTS tmp."services"(
     "id" INTEGER,
     "device" INTEGER,
     "clientId" INTEGER,
     "planId" INTEGER,
     "status" INTEGER,
     "last" TEXT,
     "created" TEXT,
     PRIMARY KEY("id")
);
CREATE TABLE IF NOT EXISTS tmp."network"(
    "id" INTEGER,
    "address" TEXT,
    "address6" TEXT,
    "last" TEXT,
    "created" TEXT,
    PRIMARY KEY("id")
);
CREATE TABLE  IF NOT EXISTS tmp."devices" (
    "id"    INTEGER NOT NULL,
    "name"  TEXT COLLATE NOCASE,
    "ip"    TEXT,
    "port"  INTEGER ,
    "qos" INTEGER ,
    "type"  TEXT,
    "user"  TEXT,
    "password"      TEXT,
    "dbname"        TEXT,
    "pool"  TEXT,
    "pool6"  TEXT,
    "pfxLength" INTEGER,
    "last"  TEXT,
    "created"       TEXT,
    PRIMARY KEY("id" AUTOINCREMENT)
);
CREATE TABLE IF NOT EXISTS tmp."plans" (
    "id"    INTEGER NOT NULL,
    "ratio" INTEGER,
    "priorityUpload" INTEGER,
    "priorityDownload" INTEGER,
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
CREATE TABLE IF NOT EXISTS tmp."config" (
    "key"   TEXT NOT NULL,
    "value" TEXT,
    "last"  TEXT,
    "created"       TEXT,
    PRIMARY KEY("key")
);

INSERT INTO tmp."plans" SELECT * FROM plans ;
INSERT INTO tmp."network" SELECT * FROM network ;
INSERT INTO tmp."config" SELECT * FROM config;
INSERT INTO tmp."devices" SELECT * FROM devices ;
INSERT INTO tmp."services" SELECT * FROM services ;

INSERT INTO tmp."devices" (id,name,ip,port,type,user,password,dbname,pool,pool6,pfxLength,last,created)
select * FROM "devices" ;
INSERT INTO tmp."devices" (id,name,ip,type,user,password,dbname,pool,pool6,pfxLength,last,created)
select * FROM "devices" ;
INSERT INTO tmp."services" (id,device,clientId,planId,status,last,created)
SELECT id,device,clientId,planId,status,"last",created FROM "services" ;
INSERT INTO tmp."plans" (id,ratio,priorityUpload,priorityDownload,limitUpload,limitDownload,
burstUpload,burstDownload,threshUpload,threshDownload,timeUpload,timeDownload,"last",created) SELECT id,ratio,priority,
priority,limitUpload,limitDownload,burstUpload,burstDownload,threshUpload,threshDownload,timeUpload,timeDownload,
"last",created FROM "plans";
INSERT INTO tmp."plans" ("id","ratio") SELECT "id","ratio" FROM "plans" ;
INSERT INTO tmp."network" (id,address,address6) SELECT id,address,prefix6 FROM "services" ;


CREATE INDEX tmp."address_index" ON "network" ("address");
CREATE INDEX tmp."address6_index" ON "network" ("address6");
CREATE INDEX tmp."device_index" ON "services" ("device");
CREATE INDEX tmp."plan_index" ON "services" ("planId");
CREATE INDEX tmp."dev_name_index" ON "devices" ("name" COLLATE nocase);
