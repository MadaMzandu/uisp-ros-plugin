CREATE TABLE IF NOT EXISTS "users" (
	"id"	INTEGER NOT NULL,
	"name"	TEXT,
	"username"	TEXT,
	"password"	TEXT,
	"session"	TEXT,
	"last"	TEXT,
	"created"	TEXT,
	PRIMARY KEY("id" AUTOINCREMENT)
);
CREATE TABLE IF NOT EXISTS "services" (
	"id"	INTEGER NOT NULL,
	"mtId"	TEXT,
	"queueId"	TEXT,
	"device"	INTEGER,
	"address"	TEXT,
	"clientId"	INTEGER,
	"planId"	INTEGER,
	"status"	INTEGER,
	"last"	TEXT,
	"created"	TEXT,
	PRIMARY KEY("id")
);
CREATE TABLE IF NOT EXISTS "devices" (
	"id"	INTEGER NOT NULL,
	"name"	TEXT,
	"ip"	TEXT,
	"type"	TEXT,
	"user"	TEXT,
	"password"	TEXT,
	"dbname"	TEXT,
	"pool"	TEXT,
	"last"	TEXT,
	"created"	TEXT,
	PRIMARY KEY("id" AUTOINCREMENT)
);
CREATE TABLE IF NOT EXISTS "plans" (
	"id"	INTEGER NOT NULL,
	"name"	TEXT,
	"downloadSpeed"	INTEGER,
	"uploadSpeed"	INTEGER,
	"downloadBurst"	INTEGER,
	"uploadBurst"	INTEGER,
	"dataUsageLimit"	INTEGER,
	"ratio"	INTEGER,
	"last"	TEXT,
	"created"	TEXT,
	PRIMARY KEY("id")
);
CREATE INDEX "xusers_session" ON "users" (
	"session"
);
CREATE INDEX "xsvc_address" ON "services" (
	"address"
);
CREATE INDEX "xsvc_device" ON "services" (
	"device"
);
CREATE INDEX "xsvc_planId" ON "services" (
	"planId"
);
CREATE INDEX "xdev_name" ON "devices" (
	"name" COLLATE nocase
);
CREATE INDEX "xusers_username" ON "users" (
	"username" COLLATE nocase
);
CREATE TABLE IF NOT EXISTS "config" (
	"key"	TEXT NOT NULL,
	"value"	TEXT,
	"last"	TEXT,
	"created"	TEXT,
	PRIMARY KEY("key")
);
