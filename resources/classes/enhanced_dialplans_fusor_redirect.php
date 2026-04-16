<?php

/**
 * Enhanced Dialplans Fusor Redirect
 *
 * This class handles the redirection from the old dialplans page to the new enhanced dialplans page using Fusor.
 */

if (class_exists('\frytimo\fusor\resources\classes\fusor')) {
	class enhanced_dialplans_fusor_redirect {
		/**
		 * Redirect from /app/dialplans/dialplans.php to /app/enhanced_dialplans/dialplans.php.
		 *
		 * @param \frytimo\fusor\resources\classes\fusor_event $event
		 *
		 * @return void
		 */
		#[\frytimo\fusor\resources\attributes\http_get(path: '/app/dialplans/dialplans.php', stage: 'before', priority: 1000)]
		public static function redirect_dialplan(\frytimo\fusor\resources\classes\fusor_event $event): void {
			if (!$event->has_query_params()) {
				header('Location: /app/enhanced_dialplans/dialplans.php' . ($event->has_query_params() ? '?' . $event->get_query_string() : ''), true, 301);
				exit();
			}
		}
	}
}
