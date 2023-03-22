DROP TABLE IF EXISTS "svctmp";
CREATE TABLE IF NOT EXISTS "svctmp"
(
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
DROP TABLE IF EXISTS "devtmp";
CREATE TABLE  IF NOT EXISTS "devtmp" (
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
DROP TABLE IF EXISTS plantmp;
CREATE TABLE IF NOT EXISTS "plantmp" (
       "id"    INTEGER NOT NULL,
       "ratio" INTEGER,
       "priority" INTEGER,
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
INSERT INTO "svctmp" (id, device, address, clientId, planId, status, "last", created)
SELECT id, device, address, clientId, planId, status, "last", created FROM "services";
INSERT INTO "devtmp" (id,name,ip,type,user,password,dbname,pool,"last",created)
SELECT id,name,ip,type,user,password,dbname,pool,"last",created FROM "devices";
INSERT INTO "plantmp" ("id","ratio","last","created") SELECT "id","ratio","last","created" FROM plans;
DROP TABLE "services";
DROP TABLE "devices" ;
DROP TABLE "plans" ;
ALTER TABLE "svctmp" RENAME to "services";
ALTER TABLE "devtmp" RENAME to "devices";
ALTER TABLE "plantmp" RENAME to "plans";
CREATE INDEX "xsvc_address" ON "services" ("address");
CREATE INDEX "xsvc_device" ON "services" ("device");
CREATE INDEX "xsvc_planId" ON "services" ("planId");
CREATE INDEX "xdev_name" ON "devices" ("name" COLLATE nocase);