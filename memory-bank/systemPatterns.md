# System Patterns & Architecture

## Database Schema & Normalization

### Core Entity Hierarchy
The TruckChecks system follows a strict hierarchical data model:

```
Stations (id, name, description)
    ↓ (station_id FK)
Trucks (id, name, relief, station_id)
    ↓ (truck_id FK)
Lockers (id, name, truck_id, notes)
    ↓ (locker_id FK)
Items (id, name, locker_id)
```

### Key Database Facts
- **stations** table: Core organizational unit
- **trucks** table: Has `station_id` foreign key (added in V4Changes.sql)
- **lockers** table: Belongs to trucks via `truck_id`
- **items** table: Belongs to lockers via `locker_id`

### Station-Based Data Isolation
- All data must be filtered by station context
- Station admins only see their assigned stations
- Superusers can select any station
- Trucks are the primary link between stations and the rest of the hierarchy

### Critical Schema Files
- `Docker/setup.sql`: Base schema (no station_id in trucks)
- `V4Changes.sql`: Adds station hierarchy and `station_id` to trucks

## User Access Patterns

### Role-Based Access
- **superuser**: Can access any station via session selection
- **station_admin**: Limited to assigned stations via user_stations table

### Station Context Resolution
1. Check user role
2. For superuser: Use session-selected station
3. For station_admin: Use assigned station(s)
4. All queries must filter by resolved station_id

## Admin Module Patterns

### Station-Aware Queries
All admin modules must:
1. Resolve current station context
2. Filter trucks by `station_id`
3. Filter lockers via truck relationships
4. Filter items via locker relationships

### Example Query Pattern
```sql
-- Get items for current station
SELECT i.*, l.name as locker_name, t.name as truck_name
FROM items i
JOIN lockers l ON i.locker_id = l.id
JOIN trucks t ON l.truck_id = t.id
WHERE t.station_id = ?
```

## Error Patterns to Avoid

### Database Column Mismatches
- Never assume trucks don't have station_id (V4Changes.sql adds it)
- Always check both setup.sql and V4Changes.sql for complete schema
- Station isolation is critical for multi-tenant functionality

### Station Context Loss
- Never show all stations' data to station admins
- Always validate station ownership before operations
- Maintain station context throughout request lifecycle
