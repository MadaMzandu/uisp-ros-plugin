CREATE TABLE IF NOT EXISTS "services"(
     "id" INT,
     "device" INT,
     "clientId" INT,
     "planId" INT,
     "status" INT,
     "last" TEXT,
     "created" TEXT,
     PRIMARY KEY("id")
);
CREATE TABLE IF NOT EXISTS "network"(
     "id" INT,
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
CREATE TABLE IF NOT EXISTS "plans" (
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
