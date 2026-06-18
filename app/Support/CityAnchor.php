<?php

namespace App\Support;

final class CityAnchor
{
    public function __construct(
        public readonly string $city,
        public readonly string $region,
        public readonly string $country,
        public readonly float $lat,
        public readonly float $lng,
        public readonly string $ianaTimezone,
    ) {}

    /**
     * Returns all 75 city anchors as a list of CityAnchor objects.
     * Coordinates are identical to EventSeeder::CITY_ANCHORS (same order).
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return [
            // United States (22)
            new self('New York', 'NY', 'US', 40.7128, -74.0060, 'America/New_York'),
            new self('Los Angeles', 'CA', 'US', 34.0522, -118.2437, 'America/Los_Angeles'),
            new self('Chicago', 'IL', 'US', 41.8781, -87.6298, 'America/Chicago'),
            new self('Houston', 'TX', 'US', 29.7604, -95.3698, 'America/Chicago'),
            new self('Phoenix', 'AZ', 'US', 33.4484, -112.0740, 'America/Phoenix'),
            new self('Philadelphia', 'PA', 'US', 39.9526, -75.1652, 'America/New_York'),
            new self('San Antonio', 'TX', 'US', 29.4241, -98.4936, 'America/Chicago'),
            new self('San Diego', 'CA', 'US', 32.7157, -117.1611, 'America/Los_Angeles'),
            new self('Dallas', 'TX', 'US', 32.7767, -96.7970, 'America/Chicago'),
            new self('San Jose', 'CA', 'US', 37.3382, -121.8863, 'America/Los_Angeles'),
            new self('Austin', 'TX', 'US', 30.2672, -97.7431, 'America/Chicago'),
            new self('San Francisco', 'CA', 'US', 37.7749, -122.4194, 'America/Los_Angeles'),
            new self('Seattle', 'WA', 'US', 47.6062, -122.3321, 'America/Los_Angeles'),
            new self('Denver', 'CO', 'US', 39.7392, -104.9903, 'America/Denver'),
            new self('Boston', 'MA', 'US', 42.3601, -71.0589, 'America/New_York'),
            new self('Las Vegas', 'NV', 'US', 36.1699, -115.1398, 'America/Los_Angeles'),
            new self('Miami', 'FL', 'US', 25.7617, -80.1918, 'America/New_York'),
            new self('Atlanta', 'GA', 'US', 33.7490, -84.3880, 'America/New_York'),
            new self('Washington', 'DC', 'US', 38.9072, -77.0369, 'America/New_York'),
            new self('Nashville', 'TN', 'US', 36.1627, -86.7816, 'America/Chicago'),
            new self('Portland', 'OR', 'US', 45.5152, -122.6784, 'America/Los_Angeles'),
            new self('New Orleans', 'LA', 'US', 29.9511, -90.0715, 'America/Chicago'),
            // Canada (8)
            new self('Toronto', 'ON', 'CA', 43.6532, -79.3832, 'America/Toronto'),
            new self('Montreal', 'QC', 'CA', 45.5019, -73.5674, 'America/Toronto'),
            new self('Vancouver', 'BC', 'CA', 49.2827, -123.1207, 'America/Vancouver'),
            new self('Calgary', 'AB', 'CA', 51.0447, -114.0719, 'America/Edmonton'),
            new self('Ottawa', 'ON', 'CA', 45.4215, -75.6972, 'America/Toronto'),
            new self('Edmonton', 'AB', 'CA', 53.5461, -113.4938, 'America/Edmonton'),
            new self('Quebec City', 'QC', 'CA', 46.8139, -71.2080, 'America/Toronto'),
            new self('Winnipeg', 'MB', 'CA', 49.8951, -97.1384, 'America/Winnipeg'),
            // Mexico (7)
            new self('Mexico City', 'CDMX', 'MX', 19.4326, -99.1332, 'America/Mexico_City'),
            new self('Guadalajara', 'Jalisco', 'MX', 20.6597, -103.3496, 'America/Mexico_City'),
            new self('Monterrey', 'Nuevo León', 'MX', 25.6866, -100.3161, 'America/Monterrey'),
            new self('Puebla', 'Puebla', 'MX', 19.0414, -98.2063, 'America/Mexico_City'),
            new self('Tijuana', 'Baja California', 'MX', 32.5149, -117.0382, 'America/Tijuana'),
            new self('Cancún', 'Q. Roo', 'MX', 21.1619, -86.8515, 'America/Cancun'),
            new self('Mérida', 'Yucatán', 'MX', 20.9674, -89.5926, 'America/Merida'),
            // Europe (30)
            new self('London', 'England', 'GB', 51.5074, -0.1278, 'Europe/London'),
            new self('Paris', 'Île-de-France', 'FR', 48.8566, 2.3522, 'Europe/Paris'),
            new self('Berlin', 'Berlin', 'DE', 52.5200, 13.4050, 'Europe/Berlin'),
            new self('Madrid', 'Madrid', 'ES', 40.4168, -3.7038, 'Europe/Madrid'),
            new self('Rome', 'Lazio', 'IT', 41.9028, 12.4964, 'Europe/Rome'),
            new self('Amsterdam', 'North Holland', 'NL', 52.3676, 4.9041, 'Europe/Amsterdam'),
            new self('Barcelona', 'Catalonia', 'ES', 41.3851, 2.1734, 'Europe/Madrid'),
            new self('Munich', 'Bavaria', 'DE', 48.1351, 11.5820, 'Europe/Berlin'),
            new self('Milan', 'Lombardy', 'IT', 45.4642, 9.1900, 'Europe/Rome'),
            new self('Vienna', 'Vienna', 'AT', 48.2082, 16.3738, 'Europe/Vienna'),
            new self('Prague', 'Bohemia', 'CZ', 50.0755, 14.4378, 'Europe/Prague'),
            new self('Lisbon', 'Lisboa', 'PT', 38.7223, -9.1393, 'Europe/Lisbon'),
            new self('Dublin', 'Leinster', 'IE', 53.3498, -6.2603, 'Europe/Dublin'),
            new self('Copenhagen', 'Capital Region', 'DK', 55.6761, 12.5683, 'Europe/Copenhagen'),
            new self('Stockholm', 'Stockholm', 'SE', 59.3293, 18.0686, 'Europe/Stockholm'),
            new self('Oslo', 'Oslo', 'NO', 59.9139, 10.7522, 'Europe/Oslo'),
            new self('Helsinki', 'Uusimaa', 'FI', 60.1699, 24.9384, 'Europe/Helsinki'),
            new self('Brussels', 'Brussels', 'BE', 50.8503, 4.3517, 'Europe/Brussels'),
            new self('Zurich', 'Zurich', 'CH', 47.3769, 8.5417, 'Europe/Zurich'),
            new self('Warsaw', 'Masovia', 'PL', 52.2297, 21.0122, 'Europe/Warsaw'),
            new self('Budapest', 'Budapest', 'HU', 47.4979, 19.0402, 'Europe/Budapest'),
            new self('Athens', 'Attica', 'GR', 37.9838, 23.7275, 'Europe/Athens'),
            new self('Lyon', 'Auvergne', 'FR', 45.7640, 4.8357, 'Europe/Paris'),
            new self('Hamburg', 'Hamburg', 'DE', 53.5511, 9.9937, 'Europe/Berlin'),
            new self('Manchester', 'England', 'GB', 53.4808, -2.2426, 'Europe/London'),
            new self('Edinburgh', 'Scotland', 'GB', 55.9533, -3.1883, 'Europe/London'),
            new self('Frankfurt', 'Hesse', 'DE', 50.1109, 8.6821, 'Europe/Berlin'),
            new self('Krakow', 'Lesser Poland', 'PL', 50.0647, 19.9450, 'Europe/Warsaw'),
            new self('Porto', 'Norte', 'PT', 41.1579, -8.6291, 'Europe/Lisbon'),
            new self('Naples', 'Campania', 'IT', 40.8518, 14.2681, 'Europe/Rome'),
            // Global hubs (8)
            new self('Tokyo', 'Kanto', 'JP', 35.6762, 139.6503, 'Asia/Tokyo'),
            new self('Seoul', 'Seoul', 'KR', 37.5665, 126.9780, 'Asia/Seoul'),
            new self('Singapore', 'Singapore', 'SG', 1.3521, 103.8198, 'Asia/Singapore'),
            new self('Sydney', 'NSW', 'AU', -33.8688, 151.2093, 'Australia/Sydney'),
            new self('Melbourne', 'Victoria', 'AU', -37.8136, 144.9631, 'Australia/Melbourne'),
            new self('Dubai', 'Dubai', 'AE', 25.2048, 55.2708, 'Asia/Dubai'),
            new self('São Paulo', 'SP', 'BR', -23.5505, -46.6333, 'America/Sao_Paulo'),
            new self('Buenos Aires', 'BA', 'AR', -34.6037, -58.3816, 'America/Argentina/Buenos_Aires'),
        ];
    }

    /**
     * Returns a map of city name => IANA timezone identifier.
     *
     * @return array<string, string>
     */
    public static function timezoneMap(): array
    {
        $map = [];
        foreach (self::all() as $anchor) {
            $map[$anchor->city] = $anchor->ianaTimezone;
        }

        return $map;
    }

    /**
     * Returns a sorted list of all city names, suitable for filter dropdowns.
     *
     * @return list<string>
     */
    public static function cityNames(): array
    {
        $names = array_map(static fn (self $a): string => $a->city, self::all());
        sort($names);

        return $names;
    }
}
