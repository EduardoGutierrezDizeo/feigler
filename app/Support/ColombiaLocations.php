<?php

namespace App\Support;

use RuntimeException;

/**
 * Departamentos y municipios de Colombia según los códigos DANE de la DIVIPOLA.
 *
 * La lista vive en resources/data/colombia-ubicaciones.json (descargada de
 * datos.gov.co, dataset «DIVIPOLA- Códigos municipios» de la entidad DANE,
 * licencia CC-BY-SA 4.0) y se lee una sola vez por petición. Nada de esto
 * consulta servicios externos en tiempo de ejecución: los códigos son la fuente
 * de verdad y de ellos salen los nombres oficiales que se muestran y se guardan.
 */
final class ColombiaLocations
{
    /**
     * Los departamentos con su código de dos dígitos, en orden alfabético.
     *
     * @return list<array{code: string, name: string}>
     */
    public static function departments(): array
    {
        return self::payload()['departments'];
    }

    /**
     * Los municipios de un departamento con su código de cinco dígitos.
     *
     * @return list<array{code: string, name: string, department: string}>
     */
    public static function cities(string $departmentCode): array
    {
        return array_values(array_filter(
            self::payload()['cities'],
            static fn (array $city): bool => $city['department'] === $departmentCode,
        ));
    }

    /**
     * Todos los municipios agrupados por el código de su departamento, para
     * dárselos a la vista de una sola vez.
     *
     * @return array<string, list<array{code: string, name: string, department: string}>>
     */
    public static function citiesByDepartment(): array
    {
        $groups = [];

        foreach (self::payload()['cities'] as $city) {
            $groups[$city['department']][] = $city;
        }

        return $groups;
    }

    /**
     * Un departamento por su código DANE, o null si no existe.
     *
     * @return array{code: string, name: string}|null
     */
    public static function department(string $code): ?array
    {
        foreach (self::departments() as $department) {
            if ($department['code'] === $code) {
                return $department;
            }
        }

        return null;
    }

    /**
     * Un municipio por su código DANE, o null si no existe.
     *
     * @return array{code: string, name: string, department: string}|null
     */
    public static function city(string $code): ?array
    {
        foreach (self::payload()['cities'] as $city) {
            if ($city['code'] === $code) {
                return $city;
            }
        }

        return null;
    }

    /**
     * Si un municipio pertenece de verdad a un departamento.
     */
    public static function cityBelongsToDepartment(string $cityCode, string $departmentCode): bool
    {
        $city = self::city($cityCode);

        return $city !== null && $city['department'] === $departmentCode;
    }

    /**
     * @return array{
     *     departments: list<array{code: string, name: string}>,
     *     cities: list<array{code: string, name: string, department: string}>
     * }
     */
    private static function payload(): array
    {
        static $payload = null;

        if ($payload !== null) {
            return $payload;
        }

        $path = resource_path('data/colombia-ubicaciones.json');

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException("No se pudo leer la lista de ubicaciones en {$path}.");
        }

        return $payload = $decoded;
    }
}
