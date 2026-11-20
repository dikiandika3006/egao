<?php

namespace App\Services\GoogleCalendar;

/** Event yang dituju sudah tidak ada di Google (mis. dihapus manual). */
class GoogleCalendarNotFound extends GoogleCalendarException {}
