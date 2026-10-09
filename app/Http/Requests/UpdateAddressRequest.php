<?php

namespace App\Http\Requests;

/**
 * Edición de una dirección propia desde Mi cuenta. Las reglas, los mensajes y
 * la bolsa «address» viven en AccountAddressRequest; aquí solo se nombra el
 * verbo y se distingue de la alta para acotar la dirección al cliente.
 */
class UpdateAddressRequest extends AccountAddressRequest
{
    //
}
