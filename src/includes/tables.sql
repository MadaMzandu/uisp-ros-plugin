CREATE TABLE "tmp"
(
    id       INT,
    device   INT,
    address  TEXT,
    clientId INT,
    planId   INT,
    status   INT,
    "last"   TEXT,
    created  TEXT
);
INSERT INTO "tmp" (id, device, address, clientId, planId, status, "last", created)
    SELECT * FROM "services";
DROP TABLE services;
ALTER TABLE tmp RENAME to services;
CREATE INDEX "xsvc_address" ON "services" ("address");
CREATE INDEX "xsvc_device" ON "services" ("device");
CREATE INDEX "xsvc_planId" ON "services" ("planId");