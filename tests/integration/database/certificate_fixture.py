"""Ephemeral synthetic identities for database acceptance; never commit generated files."""
from datetime import datetime, timedelta, timezone
from pathlib import Path
from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.x509.oid import NameOID, ExtendedKeyUsageOID
import ipaddress

p=Path('/tls');p.mkdir(exist_ok=True)
now=datetime.now(timezone.utc)
def key():return rsa.generate_private_key(public_exponent=65537,key_size=2048)
def name(value):return x509.Name([x509.NameAttribute(NameOID.COMMON_NAME,value)])
def root(identity,secret,expired=False):
 return (x509.CertificateBuilder().subject_name(name(identity)).issuer_name(name(identity)).public_key(secret.public_key())
         .serial_number(x509.random_serial_number()).not_valid_before(now-timedelta(days=3))
         .not_valid_after(now+timedelta(days=2) if not expired else now-timedelta(days=1))
         .add_extension(x509.BasicConstraints(ca=True,path_length=None),critical=True)
         .add_extension(x509.KeyUsage(False,False,False,False,False,True,True,False,False),critical=True)
         .add_extension(x509.SubjectKeyIdentifier.from_public_key(secret.public_key()),critical=False)
         .sign(secret,hashes.SHA256()))
ca_key=key();ca=root('Disposable Bridge CA',ca_key)
for file,cert in [('ca',ca),('unknown-ca',root('Unknown Root',key())),('expired-ca',root('Disposable Bridge CA',ca_key,True))]:
 (p/(file+'.crt')).write_bytes(cert.public_bytes(serialization.Encoding.PEM))
for file,identity,usage in [('server','bridge-db',ExtendedKeyUsageOID.SERVER_AUTH),('client','bridge-client',ExtendedKeyUsageOID.CLIENT_AUTH)]:
 secret=key()
 b=(x509.CertificateBuilder().subject_name(name(identity)).issuer_name(ca.subject).public_key(secret.public_key())
    .serial_number(x509.random_serial_number()).not_valid_before(now-timedelta(minutes=5)).not_valid_after(now+timedelta(days=1))
    .add_extension(x509.BasicConstraints(ca=False,path_length=None),critical=True)
    .add_extension(x509.AuthorityKeyIdentifier.from_issuer_public_key(ca_key.public_key()),critical=False)
    .add_extension(x509.SubjectKeyIdentifier.from_public_key(secret.public_key()),critical=False)
    .add_extension(x509.KeyUsage(True,False,True,False,False,False,False,False,False),critical=True)
    .add_extension(x509.ExtendedKeyUsage([usage]),critical=False))
 if file=='server':b=b.add_extension(x509.SubjectAlternativeName([x509.DNSName('bridge-db'),x509.DNSName('localhost'),x509.IPAddress(ipaddress.ip_address('127.0.0.1'))]),critical=False)
 cert=b.sign(ca_key,hashes.SHA256())
 (p/(file+'.crt')).write_bytes(cert.public_bytes(serialization.Encoding.PEM))
 (p/(file+'.key')).write_bytes(secret.private_bytes(serialization.Encoding.PEM,serialization.PrivateFormat.PKCS8,serialization.NoEncryption()))
 if file=='client':(p/'encrypted-client.key').write_bytes(secret.private_bytes(serialization.Encoding.PEM,serialization.PrivateFormat.PKCS8,serialization.BestAvailableEncryption(b'synthetic')))
for file in p.iterdir():file.chmod(0o644)
