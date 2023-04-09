CREATE TABLE IF NOT EXISTS "clients" (
       "id"    INTEGER NOT NULL,
       "company" TEXT COLLATE NOCASE,
       "firstName" TEXT COLLATE NOCASE,
       "lastName" TEXT COLLATE NOCASE,
       PRIMARY KEY("id")
);
CREATE TABLE IF NOT EXISTS "network" (
         "id"    INTEGER NOT NULL,
         "address" TEXT ,
         "address6" TEXT,
         PRIMARY KEY("id")
);
CREATE TABLE IF NOT EXISTS "services" (
       "id"    INTEGER NOT NULL,
       "clientId" INTEGER,
       "planId" INTEGER,
       "device" INTEGER ,
       "status" INTEGER ,
       "username" TEXT COLLATE NOCASE,
       "password" TEXT,
       "mac" TEXT COLLATE NOCASE,
       "duid" TEXT COLLATE NOCASE,
       "iaid" INTEGER ,
       "hotspot" INTEGER,
       "price" REAL ,
       "totalPrice" REAL ,
       "currencyCode" TEXT ,
       "callerId" TEXT,
       PRIMARY KEY("id")
);

CREATE INDEX IF NOT EXISTS "client_index" ON "services" ("clientId");
CREATE INDEX IF NOT EXISTS "plan_index" ON "services" ("planId");
CREATE INDEX IF NOT EXISTS "status_index" ON "services" ("status");
CREATE INDEX IF NOT EXISTS "did_index" ON "services" ("device");
CREATE INDEX IF NOT EXISTS "user_index" ON "services" ("username");
CREATE INDEX IF NOT EXISTS "mac_index" ON "services" ("mac");
CREATE INDEX IF NOT EXISTS "address_index" ON "network" ("address");
CREATE INDEX IF NOT EXISTS "address6_index" ON "network" ("address6");
