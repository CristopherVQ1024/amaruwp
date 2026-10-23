<?php

namespace App\Services;

use App\Models\Producto;
use App\Models\ProductoFaq;
use App\Models\ProductoFoto;

class ProductoService
{
    private const MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    private const MAX_BYTES = 5 * 1024 * 1024;

    public static function detalle(Producto $p): array
    {
        return [
            'id' => (int) $p->ID,
            'nombre' => $p->C_NOMBRE,
            'descripcion' => $p->C_DESCRIPCION,
            'precio' => $p->N_PRECIO !== null ? (float) $p->N_PRECIO : null,
            'requiere_cita' => (bool) $p->F_REQUIERE_CITA,
            'fotos' => ProductoFoto::where('ID_PRODUCTO', $p->ID)->orderBy('N_ORDEN')->orderBy('ID')
                ->get()->map(fn($f) => ['id' => (int) $f->ID, 'ruta' => $f->C_RUTA])->all(),
            'faq' => ProductoFaq::where('ID_PRODUCTO', $p->ID)->orderBy('N_ORDEN')->orderBy('ID')
                ->get()->map(fn($f) => ['pregunta' => $f->C_PREGUNTA, 'respuesta' => $f->C_RESPUESTA])->all(),
        ];
    }

    public static function guardarFaq(Producto $p, $faq): void
    {
        if (is_string($faq)) {
            $faq = json_decode($faq, true);
        }

        if (!is_array($faq)) {
            return;
        }

        ProductoFaq::where('ID_PRODUCTO', $p->ID)->delete();

        foreach (array_values($faq) as $i => $item) {
            $pregunta = trim($item['pregunta'] ?? '');
            $respuesta = trim($item['respuesta'] ?? '');

            if ($pregunta !== '' && $respuesta !== '') {
                ProductoFaq::create(['ID_PRODUCTO' => $p->ID, 'C_PREGUNTA' => $pregunta, 'C_RESPUESTA' => $respuesta, 'N_ORDEN' => $i]);
            }
        }
    }

    // guarda las fotos subidas y devuelve cuantas se aceptaron
    public static function guardarFotos(Producto $p, array $archivos): int
    {
        $dir = __DIR__ . '/../../public/uploads/productos/';

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $orden = (int) ProductoFoto::where('ID_PRODUCTO', $p->ID)->max('N_ORDEN');
        $guardadas = 0;

        foreach ($archivos as $archivo) {
            if ($archivo->getError() !== UPLOAD_ERR_OK || $archivo->getSize() > self::MAX_BYTES) {
                continue;
            }

            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($archivo->getStream()->getMetadata('uri'));

            if (!isset(self::MIMES[$mime])) {
                continue;
            }

            $nombre = 'p' . $p->ID . '_' . bin2hex(random_bytes(8)) . '.' . self::MIMES[$mime];
            $archivo->moveTo($dir . $nombre);

            ProductoFoto::create(['ID_PRODUCTO' => $p->ID, 'C_RUTA' => 'uploads/productos/' . $nombre, 'N_ORDEN' => ++$orden]);
            $guardadas++;
        }

        return $guardadas;
    }

    public static function borrarArchivoFoto(ProductoFoto $foto): void
    {
        $ruta = __DIR__ . '/../../public/' . $foto->C_RUTA;

        if (is_file($ruta)) {
            unlink($ruta);
        }
    }
}
