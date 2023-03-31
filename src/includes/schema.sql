CREATE TABLE IF NOT EXISTS "services"(
             "id" INT,
             "device" INT,
             "address" TEXT,
             "prefix6"   TEXT,
             "clientId" INT,
             "planId" INT,
             "status" INT,
             "last" TEXT,
             "created" TEXT
);
CREATE TABLE IF NOT EXISTS "devices" (
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

CREATE INDEX "xsvc_address" ON "services" ("address");
CREATE INDEX "xsvc_prefix6" ON "services" ("prefix6");
CREATE INDEX "xsvc_device" ON "services" ("device");
CREATE INDEX "xsvc_planId" ON "services" ("planId");
CREATE INDEX "xdev_name" ON "devices" ("name" COLLATE nocase);
