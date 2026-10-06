<?php

namespace App\Services\Booking;

use RuntimeException;

/**
 * Error esperado al agendar (horario ocupado, fuera de horario...). El
 * mensaje se le puede mostrar al agente o al usuario tal cual.
 */
class BookingException extends RuntimeException {}
