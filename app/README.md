## Ejemplos de uso
## Servidor
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