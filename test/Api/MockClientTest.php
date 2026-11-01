<?php

namespace Greenter\Sunat\GRE\Test\Api;

use Greenter\Sunat\GRE\Api\AuthApi;
use Greenter\Sunat\GRE\Api\CpeApi;
use Greenter\Sunat\GRE\Configuration;
use Greenter\Sunat\GRE\Model\CpeDocument;
use Greenter\Sunat\GRE\Model\CpeDocumentArchivo;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Recorre token, envio y consulta contra respuestas simuladas, sin red: sirve para
 * comprobar que el cliente funciona con cada version de Guzzle soportada.
 */
class MockClientTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private $historial = [];

    private function client(array $respuestas): Client
    {
        $pila = HandlerStack::create(new MockHandler($respuestas));
        $pila->push(Middleware::history($this->historial));

        return new Client(['handler' => $pila]);
    }

    public function testGetToken()
    {
        $client = $this->client([
            new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"abc123","token_type":"JWT","expires_in":3600}'),
        ]);

        $token = (new AuthApi($client))->getToken('password', 'https://api-cpe.sunat.gob.pe', 'id', 'secret', '20000000001MODDATOS', 'moddatos');

        $this->assertSame('abc123', $token->getAccessToken());
        $this->assertSame(3600, $token->getExpiresIn());
        $peticion = $this->historial[0]['request'];
        $this->assertSame('POST', $peticion->getMethod());
        $this->assertStringContainsString('/clientessol/id/oauth2/token', (string) $peticion->getUri());
        $this->assertStringContainsString('username=20000000001MODDATOS', (string) $peticion->getBody());
    }

    public function testEnviarYConsultar()
    {
        $client = $this->client([
            new Response(200, ['Content-Type' => 'application/json'], '{"numTicket":"T-123","fecRecepcion":"2023-01-13T10:00:00"}'),
            new Response(200, ['Content-Type' => 'application/json'], '{"codRespuesta":"0","indCdrGenerado":"1","arcCdr":"'.base64_encode('cdr').'"}'),
        ]);
        $config = Configuration::getDefaultConfiguration()->setAccessToken('abc123');
        $api = new CpeApi($client, $config->setHost($config->getHostFromSettings(1)));

        $doc = (new CpeDocument())->setArchivo((new CpeDocumentArchivo())
            ->setNomArchivo('20000000001-09-T001-1.zip')
            ->setArcGreZip(base64_encode('zip'))
            ->setHashZip(hash('sha256', 'zip')));
        $envio = $api->enviarCpe('20000000001-09-T001-1', $doc);
        $estado = $api->consultarEnvio($envio->getNumTicket());

        $this->assertSame('T-123', $envio->getNumTicket());
        $this->assertSame('0', $estado->getCodRespuesta());
        $this->assertSame('cdr', base64_decode($estado->getArcCdr()));
        $this->assertSame('Bearer abc123', $this->historial[0]['request']->getHeaderLine('Authorization'));
        $this->assertStringContainsString('/contribuyente/gem/comprobantes/20000000001-09-T001-1', (string) $this->historial[0]['request']->getUri());
        $this->assertStringContainsString('/contribuyente/gem/comprobantes/envios/T-123', (string) $this->historial[1]['request']->getUri());
    }
}
