-- Retira el banco de medicion de CP-09, dejando los cuatro estanques semilla.
-- Las lecturas y umbrales caen por ON DELETE CASCADE.
DELETE FROM estanque WHERE codigo ~ '^EST-(0[5-9]|1[0-9]|20)$';
