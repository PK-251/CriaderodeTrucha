-- Reversión de V4__retencion.sql

SELECT remove_retention_policy('lectura', if_exists => TRUE);
SELECT remove_compression_policy('lectura', if_exists => TRUE);

-- Descomprimir los fragmentos antes de retirar la configuración de compresión;
-- de lo contrario el ALTER TABLE falla si algún fragmento ya está comprimido.
SELECT decompress_chunk(c, if_compressed => TRUE)
FROM show_chunks('lectura') c;

ALTER TABLE lectura SET (timescaledb.compress = FALSE);

SELECT set_chunk_time_interval('lectura', INTERVAL '7 days');
