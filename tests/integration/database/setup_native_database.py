"""Synthetic canonical Cacti account for root-owned native-code acceptance."""
import sys
import pymysql
for path, host, identity in [('/sock/mysql.sock','%',True),('/sock/only.sock','localhost',False)]:
    db=pymysql.connect(unix_socket=path,user='root',password=b'disposable-bridge-root',ssl_disabled=True)
    with db.cursor() as cur:
        cur.execute('CREATE DATABASE cacti')
        cur.execute('CREATE USER %s@%s IDENTIFIED BY %s '+('REQUIRE X509' if identity else ''),
                    ('gnmi_native',host," '\"\\ native \u2603 $() `command` "))
        cur.execute('GRANT ALL ON cacti.* TO %s@%s',('gnmi_native',host))
        cur.execute('GRANT SELECT ON mysql.time_zone_name TO %s@%s',('gnmi_native',host))
    db.close()
