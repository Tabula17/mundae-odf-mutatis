# Mundae ODF Mutatis

Servidor de conversión de documentos ODF (Open Document Format) basado en Swoole y unoserver.

## Descripción

Mundae ODF Mutatis es un servicio de conversión de documentos que utiliza LibreOffice/unoserver como backend para realizar conversiones de formatos ODF a otros formatos como PDF. Implementa un sistema cliente-servidor con capacidades de procesamiento asíncrono y balanceo de carga.

## Características

- Servidor TCP con Swoole
- Procesamiento síncrono y asíncrono
- Sistema de cola con Redis
- Balanceo de carga de instancias unoserver
- Soporte para mTLS (mutual TLS)
- Monitoreo de salud de servidores
- Workers para procesamiento en segundo plano

## Requisitos

- PHP 8.1 o superior
- Swoole PHP Extension
- Redis (para funcionalidad de cola)
- LibreOffice/unoserver

## Instalación

```bash
composer require tabula17/mundae-odf-mutatis
```

## Configuración

Crear un archivo `config/config.php` con la siguiente estructura:

```php
return [
    'server' => [
        'host' => '127.0.0.1',
        'port' => 9501,
        'workers' => 4,
        'task_workers' => 8,
        'log_file' => '/path/to/log/file.log'
    ],
    'unoserver_instances' => [
        ['host' => 'localhost', 'port' => 2002],
        ['host' => 'localhost', 'port' => 2003]
    ],
    'queue' => [
        'enabled' => true,
        'host' => 'localhost',
        'port' => 6379,
        'channel' => 'conversions'
    ],
    'concurrency' => 10,
    'ssl' => [
        'enabled' => false,
        'ssl_cert_file' => '/path/to/cert.pem',
        'ssl_key_file' => '/path/to/key.pem',
        'ssl_client_cert_file' => '/path/to/client-cert.pem'
    ]
];
```

## Uso

### Iniciar el servidor

```bash
php app/server.php
```

### Iniciar el worker

```bash
php app/worker.php
```

### Ejemplo de uso del cliente

```php
$client = new ConversionClient();
$result = $client->convert(
    '/ruta/documento.odt',
    'pdf',
    '/ruta/salida.pdf',
    true,  // async
    false  // no usar cola
);
```

## Características de Seguridad

- Soporte para mTLS (autenticación mutua TLS)
- Validación de certificados SSL
- Control de concurrencia
- Monitoreo de salud de servidores

## Licencia

Este proyecto está licenciado bajo [MIT License].

## Contribución

Las contribuciones son bienvenidas. Por favor, asegúrate de actualizar las pruebas según corresponda.
