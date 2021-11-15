PRAGMA foreign_keys=OFF;
BEGIN TRANSACTION;
CREATE TABLE IF NOT EXISTS "config" (
                                        "key"   TEXT NOT NULL,
                                        "value" TEXT,
                                        "last"  TEXT,
                                        "created"       TEXT,
                                        PRIMARY KEY("key")
    );
INSERT INTO config VALUES('ppp_pool','10.99.0.0/16',NULL,'7/17/2021 8:07');
INSERT INTO config VALUES('router_ppp_pool','true',NULL,'7/17/2021 8:07');
INSERT INTO config VALUES('excl_addr','',NULL,'7/17/2021 8:07');
INSERT INTO config VALUES('active_list','',NULL,'7/17/2021 8:07');
INSERT INTO config VALUES('disabled_list','disabled',NULL,'7/17/2021 8:07');
INSERT INTO config VALUES('disabled_profile','disabled',NULL,'7/17/2021 8:07');
INSERT INTO config VALUES('unsuspend_date_fix','false',NULL,'7/17/2021 8:07');
INSERT INTO config VALUES('unsuspend_fix_wait','5',NULL,'7/17/2021 8:07');
INSERT INTO config VALUES('pppoe_user_attr','pppoeUsername',NULL,'7/17/2021 8:07');
INSERT INTO config VALUES('pppoe_pass_attr','pppoePassword',NULL,'7/17/2021 8:07');
INSERT INTO config VALUES('device_name_attr','deviceName',NULL,'7/17/2021 8:07');
INSERT INTO config VALUES('mac_addr_attr','dhcpMacAddress',NULL,'7/17/2021 8:07');
INSERT INTO config VALUES('ip_addr_attr','ipAddress',NULL,'7/17/2021 8:07');
INSERT INTO config VALUES('version','1.8.1',NULL,'2021-10-30 21:42:09');
INSERT INTO config VALUES('disabled_rate','1',NULL,'2021-10-30 21:42:09');
COMMIT;
