CREATE TABLE IF NOT EXISTS "clients" (
       "id"    INTEGER NOT NULL,
       "company" TEXT,
       "firstName" TEXT,
       "lastName" TEXT ,
       PRIMARY KEY("id")
);
CREATE TABLE IF NOT EXISTS "network" (
         "id"    INTEGER NOT NULL,
         "deviceId" INTEGER ,
         "device" TEXT,
         "address" TEXT ,
         "prefix6" TEXT,
         PRIMARY KEY("id")
);
CREATE TABLE IF NOT EXISTS "services" (
       "id"    INTEGER NOT NULL,
       "clientId" INTEGER,
       "planId" INTEGER,
       "device" INTEGER ,
       "status" INTEGER ,
       "price" REAL ,
       "totalPrice" REAL ,
       "currencyCode" TEXT ,
       "attributes" TEXT,
       PRIMARY KEY("id")
);

CREATE INDEX IF NOT EXISTS "net_deviceId" ON "network" ("deviceId");
CREATE INDEX IF NOT EXISTS "svc_clientId" ON "services" ("clientId");
CREATE INDEX IF NOT EXISTS "svc_planId" ON "services" ("planId");
CREATE INDEX IF NOT EXISTS "svc_status" ON "services" ("status");
CREATE INDEX IF NOT EXISTS "svc_device" ON "services" ("device");
