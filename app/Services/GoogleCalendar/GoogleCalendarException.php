<?php

namespace App\Services\GoogleCalendar;

use RuntimeException;

/** Kesalahan integrasi Google Calendar; pesannya aman ditampilkan ke Owner. */
class GoogleCalendarException extends RuntimeException {}
