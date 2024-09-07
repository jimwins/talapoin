.PHONY: up down mysql

up: .env config.ini
	docker-compose up -d

down:
	docker-compose down

mysql: .env
	. ./.env; \
	tty=-T; \
	tty -s && tty=; \
	exec docker-compose exec $${tty} db \
		mysql -u"$${MYSQL_USER}" -p"$${MYSQL_PASSWORD}" \
		--default-character-set=utf8mb4 --local-infile "$${MYSQL_DATABASE}"

.env:
	@echo "You need to create a .env file, see sample.env for an example"
	@exit 1

config.ini:
	@echo "You need to create a config.ini file, see config.ini.sample for an example"
	@exit 1
