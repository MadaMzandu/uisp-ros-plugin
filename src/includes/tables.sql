CREATE TABLE tmp AS SELECT id,device,address,clientId,planId,status,last,created FROM services;
DROP TABLE services;
ALTER TABLE tmp RENAME to services;
CREATE INDEX "xsvc_address" ON "services" ("address");
CREATE INDEX "xsvc_device" ON "services" ("device");
CREATE INDEX "xsvc_planId" ON "services" ("planId");
