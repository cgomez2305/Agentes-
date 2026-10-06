<?php

namespace App\Services\Onboarding;

use App\Models\CatalogItem;
use InvalidArgumentException;

/**
 * Importa productos o servicios desde un CSV con encabezados:
 * nombre,precio[,descripcion,duracion_min,sku,tipo]. Actualiza por SKU o,
 * si no hay SKU, por nombre. Debe ejecutarse con el negocio activo.
 */
class CatalogImporter
{
    /**
     * @return int Ítems creados o actualizados.
     */
    public function import(string $path): int
    {
        $handle = fopen($path, 'r');
        $first = fgets($handle);
        // Excel en español suele exportar con punto y coma.
        $delimiter = substr_count((string) $first, ';') > substr_count((string) $first, ',') ? ';' : ',';
        rewind($handle);

        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), fgetcsv($handle, separator: $delimiter, escape: '') ?: []);

        if (! in_array('nombre', $header, true)) {
            fclose($handle);
            throw new InvalidArgumentException('El archivo debe tener una columna "nombre" (y opcionalmente precio, descripcion, duracion_min, sku, tipo).');
        }

        $count = 0;
        while (($row = fgetcsv($handle, separator: $delimiter, escape: '')) !== false) {
            if (count($row) !== count($header) || blank($row[array_search('nombre', $header, true)])) {
                continue;
            }

            $data = array_combine($header, array_map('trim', $row));
            $price = preg_replace('/\D/', '', $data['precio'] ?? '');

            CatalogItem::updateOrCreate(
                filled($data['sku'] ?? null) ? ['sku' => $data['sku']] : ['name' => $data['nombre']],
                [
                    'name' => $data['nombre'],
                    'kind' => ($data['tipo'] ?? '') === 'producto' ? 'product' : 'service',
                    'description' => ($data['descripcion'] ?? '') ?: null,
                    'price' => $price === '' ? null : (int) $price,
                    'duration_minutes' => ($data['duracion_min'] ?? '') === '' ? null : (int) $data['duracion_min'],
                    'is_available' => true,
                ],
            );
            $count++;
        }

        fclose($handle);

        return $count;
    }
}
