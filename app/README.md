## Ejemplos de uso 

## Instancias de Unoserver
Para usar el servicio debes instalar [Unoserver](https://github.com/unoconv/unoserver).
Una vez instalado, puedes iniciar múltiples instancias de Unoserver en diferentes puertos para manejar cargas concurrentes.
```bash
# Ejemplo iniciando 3 instancias
unoserver --port 2003 &
unoserver --port 2004 &
unoserver --port 2005 &
```
Con systemd, puedes crear un servicio para cada instancia de Unoserver:
```ini
# Usando systemd para múltiples instancias
for port in {2003..2005}; do
cat > /etc/systemd/system/unoserver-$port.service <<EOF
[Unit]
Description=Unoserver instance $port

[Service]
ExecStart=/usr/bin/unoserver --port $port
Restart=always
EOF

systemctl enable unoserver-$port
systemctl start unoserver-$port
done
```
## Servidor
### Iniciar el servidor de conversión manualmente  
```bash
nohup php server.php > server.log 2>&1 &

# Verificar que están corriendo
ps aux | grep server.php
```
### Supervisión (con systemd o supervisor):
Para supervisar el servidor de conversión, puedes usar `systemd` o `supervisor`. 

Aquí tienes un ejemplo de configuración para `supervisor`:
```ini
[program:conversion_server]
command=php /path/to/server.php
autorestart=true
stderr_logfile=/var/log/conversion_server.err.log
stdout_logfile=/var/log/conversion_server.out.log
```
Para usar `systemd`, crea un archivo `/etc/systemd/system/odf-server.service`:
```ini
[Unit]
Description=ODF Conversion Server
After=network.target
[Service]
User=www-data
ExecStart=/usr/bin/php /path/to/server.php
Restart=always
RestartSec=5
[Install]
WantedBy=multi-user.target
```
Luego, habilita y arranca el servicio:
```bash 
systemctl enable odf-server
systemctl start odf-server
```
### Supervisión de salud
Para monitorear la salud del servidor puedes utilizar el script `app/health_check.php`.
```bash
# Verificación simple
php health_check.php

# Verificación detallada
php health_check.php --host=localhost --port=9501 --timeout=5 --verbose
```
Este script se puede integrar en un cron job o en un sistema de monitoreo para verificar periódicamente la salud del servidor.
#### Nagios/Icinga
Para integrar con Nagios o Icinga, puedes crear un plugin personalizado que llame al script de verificación de salud y retorne el estado adecuado. Aquí tienes un ejemplo básico:

```bash
define command {
  command_name check_tcp_service
  command_line /usr/bin/php /path/to/health_check.php --host=$HOSTADDRESS$ --port=$ARG1$ --timeout=$ARG2$
}
```
#### Cron para verificación periódica
Puedes configurar un cron job para ejecutar el script de verificación periódicamente. Por ejemplo, para verificar cada 5 minutos:

```bash
*/5 * * * * /usr/bin/php /path/to/health_check.php --host=localhost --port=9501 --timeout=5 >> /var/log/health_check.log 2>&1
```
o
```bash
*/5 * * * * /usr/bin/php /path/to/health_check.php --host=localhost --port=9501 && echo "Service OK" || echo "Service FAILED" | mail -s "Service Status" admin@example.com
```
#### Docker/Kubernetes
```yaml
livenessProbe:
  exec:
    command: ["php", "/app/health_check.php"]
  initialDelaySeconds: 30
  periodSeconds: 10
```

### Uso de mTLS (Mutual TLS)
Antes de habilitar mTLS, asegúrate de tener los certificados necesarios y que tu servidor esté configurado para usarlos. 
#### Generación de certificados
```bash
# Crear CA raíz
openssl genrsa -out ca.key 2048
openssl req -new -x509 -days 365 -key ca.key -out ca.crt -subj "/CN=Root CA"

# Certificado para el Servicio 
openssl genrsa -out service_a.key 2048
openssl req -new -key service_a.key -out service_a.csr -subj "/CN=service_a"
openssl x509 -req -in service_a.csr -CA ca.crt -CAkey ca.key -CAcreateserial -out service_a.crt -days 365

# Certificado para el Cliente (Debe estar firmado por la misma CA)
openssl genrsa -out client.key 2048
openssl req -new -key client.key -out client.csr -subj "/CN=client"
openssl x509 -req -in client.csr -CA ca.crt -CAkey ca.key -CAcreateserial -out client.crt -days 365
```
#### Configuración del servidor para mTLS
Para habilitar mTLS en tu servidor, asegúrate de que la configuración de la aplicación incluya los certificados generados:
```php
// config/config.php
return [
    'server' => [...],
    'unoserver_instances' => [...],
    'concurrency' => ...,
    'queue' => [...],
    'ssl' => [
        'enabled' => true, // Habilitar mTLS
        'ssl_cert_file' => '/path/to/service_a.crt', // Certificado del servidor
        'ssl_key_file' => '/path/to/service_a.key', // Clave privada del servidor
        'ssl_client_cert_file' => true,
        'ssl_verify_peer' => true,
        'ssl_allow_self_signed' => true // Si estás usando certificados autofirmados
    ],
];
```
En la instancia del middleware del servidor agregar los clientes permitidos:
```php
$mtlsMiddleware = new TCPmTLSAuthMiddleware($logger);
$mtlsMiddleware->allowClients(['client1.example.com', '*.trusted-domain.com']);
```
Y pasarlo al constructor de `ConversionServer`:
```php
$server = new ConversionServer(
   ...,
   mtlsMiddleware: $mtlsMiddleware,
   ...
);
```


## Workers

### Cómo Ejecutar Múltiples Workers
Para ejecutar múltiples workers de forma concurrente, puedes usar un script de shell que lance varios procesos en segundo plano. Aquí tienes un ejemplo:

```bash
# Ejecutar 4 workers
for i in {1..4}; do
  nohup php worker.php > worker_$i.log 2>&1 &
done

# Verificar que están corriendo
ps aux | grep worker.php
```

### Supervisión con systemd

Crea un archivo /etc/systemd/system/odf-worker@.service:
```ini
[Unit]
Description=ODF Conversion Worker %i

[Service]
User=www-data
ExecStart=/usr/bin/php /path/to/worker.php
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```
Luego, habilita y arranca los servicios:
```bash
systemctl start odf-worker@{1..4}.service
systemctl enable odf-worker@{1..4}.service
```