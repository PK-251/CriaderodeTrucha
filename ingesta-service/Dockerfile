# ingesta-service — Python 3.12 · FastAPI · paho-mqtt
# Fase 0: entorno base y endpoint de salud.
# El suscriptor MQTT, el validador de calidad y el búfer llegan en la Fase 4.
FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

WORKDIR /srv

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . /srv

# Directorio del búfer persistente para el corte de enlace (RNF-02)
RUN mkdir -p /data

EXPOSE 8001
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8001"]
