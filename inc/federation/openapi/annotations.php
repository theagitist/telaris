<?php
declare(strict_types=1);

/**
 * OpenAPI 3.1 annotations for the Telaris federation surface.
 *
 * This file is scanned by `zircote/swagger-php` at request time on
 * GET /api/pluriverse/openapi.json. The classes here exist only to carry
 * PHP 8 attributes; they are NEVER instantiated, called, or autoloaded by
 * the runtime handlers. The actual endpoints live in sibling handler files
 * (e.g. ../identity_handler.php).
 *
 * Spec: P2P federation plan v10 § Pluriverse protocol → Standards and crypto
 *       (line 482), § Instance-side endpoint catalogue (line 457).
 *
 * The doc's `info.version` MUST match the runtime `protocol_version` string
 * served by /api/pluriverse/identity. Bump both at the same time.
 */

namespace Telaris\Federation\OpenApi;

use OpenApi\Attributes as OA;

#[OA\OpenApi(
    openapi: '3.1.0',
    info: new OA\Info(
        version: '1.0',
        title: 'Telaris Pluriverse Protocol',
        description: 'Federation surface exposed by every Telaris instance under /api/pluriverse/*. '
            . 'Symmetric prefix with www.telaris.ca (the Pluriverse). See '
            . 'P2P federation plan v10 for the full specification.',
        license: new OA\License(
            name: 'GPL-3.0-or-later',
            identifier: 'GPL-3.0-or-later'
        )
    ),
    servers: [
        new OA\Server(url: 'https://{hostname}', description: 'A Telaris instance', variables: [
            new OA\ServerVariable(serverVariable: 'hostname', default: 'starmaps.polivoxia.ca'),
        ]),
    ],
    tags: [
        new OA\Tag(name: 'pluriverse-public', description: 'Public read endpoints (no signature required).'),
        new OA\Tag(name: 'pluriverse-meta', description: 'Protocol metadata and discovery.'),
    ]
)]
final class OpenApiDocument {}

#[OA\Schema(
    schema: 'IdentityEnvelope',
    description: 'Identity envelope returned by GET /api/pluriverse/identity.',
    required: [
        'hostname',
        'label',
        'telaris_version',
        'protocol_version',
        'public_key',
        'public_key_fingerprint',
        'pluriverse_endpoint',
    ],
    properties: [
        new OA\Property(property: 'hostname', type: 'string', example: 'starmaps.polivoxia.ca'),
        new OA\Property(property: 'label', type: 'string', description: 'Editorial label chosen by the operator.'),
        new OA\Property(property: 'telaris_version', type: 'string', example: '6.11.5', description: 'Semver version of the Telaris instance software.'),
        new OA\Property(property: 'protocol_version', type: 'string', enum: ['1.0'], description: 'Pluriverse protocol version this instance speaks.'),
        new OA\Property(property: 'public_key', type: 'string', format: 'byte', description: 'Base64-encoded Ed25519 public key (32 bytes).'),
        new OA\Property(property: 'public_key_fingerprint', type: 'string', minLength: 22, maxLength: 22, description: 'Base64url-encoded first 16 bytes of SHA-256(public_key), no padding.'),
        new OA\Property(property: 'pluriverse_endpoint', type: 'string', format: 'uri', example: 'https://www.telaris.ca/api/pluriverse/identity'),
    ]
)]
final class IdentityEnvelopeSchema {}

#[OA\Schema(
    schema: 'ProblemDetails',
    description: 'RFC 9457 Problem Details for HTTP APIs. Returned for every error response.',
    required: ['type', 'title', 'status', 'detail', 'instance', 'code'],
    properties: [
        new OA\Property(property: 'type', type: 'string', format: 'uri', example: 'https://www.telaris.ca/docs/errors/not_found'),
        new OA\Property(property: 'title', type: 'string', example: 'Not Found'),
        new OA\Property(property: 'status', type: 'integer', example: 404),
        new OA\Property(property: 'detail', type: 'string', description: 'Longer human-readable explanation.'),
        new OA\Property(property: 'instance', type: 'string', description: 'Request path on which the error occurred.'),
        new OA\Property(property: 'code', type: 'string', description: 'Stable machine-readable error identifier.', example: 'not_found'),
        new OA\Property(property: 'retry_after', type: 'integer', description: 'Optional. Present on 429/503 responses.', nullable: true),
    ]
)]
final class ProblemDetailsSchema {}

#[OA\Get(
    path: '/api/pluriverse/identity',
    operationId: 'getIdentity',
    summary: 'Instance identity envelope.',
    description: 'Returns this instance\'s federation identity: hostname, label, Telaris version, '
        . 'protocol version, base64 public key, fingerprint, and configured Pluriverse endpoint. '
        . 'Public-read; no authentication. Rate limit 60 req/min/IP.',
    tags: ['pluriverse-public', 'pluriverse-meta'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Identity envelope.',
            content: new OA\JsonContent(ref: '#/components/schemas/IdentityEnvelope')
        ),
        new OA\Response(
            response: 405,
            description: 'Method not allowed (only GET is accepted).',
            content: new OA\JsonContent(ref: '#/components/schemas/ProblemDetails')
        ),
        new OA\Response(
            response: 429,
            description: 'Rate limit exceeded (60 req/min/IP).',
            content: new OA\JsonContent(ref: '#/components/schemas/ProblemDetails')
        ),
        new OA\Response(
            response: 503,
            description: 'Instance has not been provisioned with a federation identity.',
            content: new OA\JsonContent(ref: '#/components/schemas/ProblemDetails')
        ),
    ]
)]
final class GetIdentityEndpoint {}

#[OA\Get(
    path: '/api/pluriverse/openapi.json',
    operationId: 'getOpenApiSpec',
    summary: 'OpenAPI 3.1 spec for this instance\'s Pluriverse surface.',
    description: 'Returns the OpenAPI 3.1 specification for every /api/pluriverse/* endpoint '
        . 'served by this instance. Public-read; no authentication. Rate limit 60 req/min/IP. '
        . 'Cache-validate via the Last-Modified header.',
    tags: ['pluriverse-public', 'pluriverse-meta'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OpenAPI 3.1 document.',
            content: new OA\JsonContent(type: 'object')
        ),
        new OA\Response(
            response: 304,
            description: 'Not Modified (when If-Modified-Since matches Last-Modified).'
        ),
        new OA\Response(
            response: 405,
            description: 'Method not allowed (only GET is accepted).',
            content: new OA\JsonContent(ref: '#/components/schemas/ProblemDetails')
        ),
        new OA\Response(
            response: 429,
            description: 'Rate limit exceeded (60 req/min/IP).',
            content: new OA\JsonContent(ref: '#/components/schemas/ProblemDetails')
        ),
    ]
)]
final class GetOpenApiEndpoint {}
