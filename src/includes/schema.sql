CREATE TABLE IF NOT EXISTS "services"(
     "id" INTEGER,
     "device" INTEGER,
     "clientId" INTEGER,
     "planId" INTEGER,
     "status" INTEGER,
     "last" TEXT,
     "created" TEXT,
     PRIMARY KEY("id")
);
CREATE TABLE IF NOT EXISTS "network"(
     "id" INTEGER,
     "address" TEXT,
     "address6" TEXT,
     "last" TEXT,
     "created" TEXT,
     PRIMARY KEY("id")
);
CREATE TABLE IF NOT EXISTS "devices" (
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
CREATE TABLE IF NOT EXISTS "plans" (
    "id"    INTEGER NOT NULL,
    "name" TEXT,
    "ratio" INTEGER,
    "uploadSpeed" INTEGER,
    "downloadSpeed" INTEGER,
    "uploadOverride" INTEGER,
    "downloadOverride" INTEGER,
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
CREATE TABLE IF NOT EXISTS "config" (
    "key"   TEXT NOT NULL,
    "value" TEXT,
    "last"  TEXT,
    "created"       TEXT,
    PRIMARY KEY("key")
);

CREATE INDEX "address_index" ON "network" ("address");
CREATE INDEX "address6_index" ON "network" ("address6");
CREATE INDEX "device_index" ON "services" ("device");
CREATE INDEX "plan_index" ON "services" ("planId");
CREATE INDEX "dev_name_index" ON "devices" ("name" COLLATE nocase);
