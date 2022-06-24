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
INSERT INTO "svctmp" (id, device, address, clientId, planId, status, "last", created)
    SELECT * FROM "services";
INSERT INTO "devtmp" (id,name,ip,type,user,password,dbname,pool,"last",created) SELECT * FROM "devices";
DROP TABLE "services";
DROP TABLE "devices" ;
ALTER TABLE "svctmp" RENAME to "services";
ALTER TABLE "devtmp" RENAME to "devices";
CREATE INDEX "xsvc_address" ON "services" ("address");
CREATE INDEX "xsvc_device" ON "services" ("device");
CREATE INDEX "xsvc_planId" ON "services" ("planId");
CREATE INDEX "xdev_name" ON "devices" ("name" COLLATE nocase);