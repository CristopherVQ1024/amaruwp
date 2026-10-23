<?php

namespace App\Controllers;

use App\Models\Producto;
use App\Models\ProductoFoto;
use App\Services\ProductoService;

class ProductoController
{
    private function propio($request, $id): ?Producto
    {
        return Producto::where('ID', $id)->where('ID_EMPRESA', $request->getAttribute('token')->id_empresa)->where('F_STATUS', 1)->first();
    }

    private function noEncontrado()
    {
        return jsonResponse(['status' => false, 'error' => 'Producto no encontrado'], 404);
    }

    private function archivos($request): array
    {
        $files = $request->getUploadedFiles()['fotos'] ?? [];

        return is_array($files) ? $files : [$files];
    }

    public function listar($request, $response)
    {
        $items = Producto::where('ID_EMPRESA', $request->getAttribute('token')->id_empresa)->where('F_STATUS', 1)
            ->orderBy('ID')->get()->map(fn($p) => ProductoService::detalle($p))->all();

        return jsonResponse(['status' => true, 'productos' => $items]);
    }

    public function ver($request, $response, $args)
    {
        $p = $this->propio($request, $args['id']);

        return $p ? jsonResponse(['status' => true, 'producto' => ProductoService::detalle($p)]) : $this->noEncontrado();
    }

    public function crear($request, $response)
    {
        $b = bodyOf($request);

        if (trim($b['nombre'] ?? '') === '') {
            return jsonResponse(['status' => false, 'error' => 'El nombre es obligatorio'], 422);
        }

        $p = Producto::create([
            'ID_EMPRESA' => $request->getAttribute('token')->id_empresa,
            'C_NOMBRE' => trim($b['nombre']),
            'C_DESCRIPCION' => $b['descripcion'] ?? null,
            'N_PRECIO' => isset($b['precio']) && $b['precio'] !== '' ? $b['precio'] : null,
            'F_REQUIERE_CITA' => filter_var($b['requiere_cita'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
        ]);

        ProductoService::guardarFaq($p, $b['faq'] ?? null);
        ProductoService::guardarFotos($p, $this->archivos($request));

        return jsonResponse(['status' => true, 'producto' => ProductoService::detalle($p)], 201);
    }

    public function actualizar($request, $response, $args)
    {
        $p = $this->propio($request, $args['id']);

        if (!$p) {
            return $this->noEncontrado();
        }

        $b = bodyOf($request);
        $campos = [];

        if (isset($b['nombre']) && trim($b['nombre']) !== '') {
            $campos['C_NOMBRE'] = trim($b['nombre']);
        }
        if (array_key_exists('descripcion', $b)) {
            $campos['C_DESCRIPCION'] = $b['descripcion'];
        }
        if (array_key_exists('precio', $b)) {
            $campos['N_PRECIO'] = $b['precio'] === '' ? null : $b['precio'];
        }
        if (array_key_exists('requiere_cita', $b)) {
            $campos['F_REQUIERE_CITA'] = filter_var($b['requiere_cita'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }

        $p->update($campos);
        ProductoService::guardarFaq($p, $b['faq'] ?? null);
        ProductoService::guardarFotos($p, $this->archivos($request));

        return jsonResponse(['status' => true, 'producto' => ProductoService::detalle($p->refresh())]);
    }

    public function eliminar($request, $response, $args)
    {
        $p = $this->propio($request, $args['id']);

        if (!$p) {
            return $this->noEncontrado();
        }

        $p->update(['F_STATUS' => 0]);

        return jsonResponse(['status' => true]);
    }

    public function subirFotos($request, $response, $args)
    {
        $p = $this->propio($request, $args['id']);

        if (!$p) {
            return $this->noEncontrado();
        }

        $n = ProductoService::guardarFotos($p, $this->archivos($request));

        return jsonResponse(['status' => $n > 0, 'guardadas' => $n, 'producto' => ProductoService::detalle($p)], $n > 0 ? 201 : 422);
    }

    public function borrarFoto($request, $response, $args)
    {
        $p = $this->propio($request, $args['id']);
        $foto = $p ? ProductoFoto::where('ID', $args['fotoId'])->where('ID_PRODUCTO', $p->ID)->first() : null;

        if (!$foto) {
            return jsonResponse(['status' => false, 'error' => 'Foto no encontrada'], 404);
        }

        ProductoService::borrarArchivoFoto($foto);
        $foto->delete();

        return jsonResponse(['status' => true]);
    }
}
