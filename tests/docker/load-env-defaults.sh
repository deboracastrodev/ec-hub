#!/bin/bash
# Helper sourceável: load_env_defaults <arquivo>
# Lê linhas KEY=VALUE de um arquivo de env e só atribui as variáveis que
# ainda NÃO estão definidas no ambiente (o env exportado tem precedência).
# Ignora comentários, linhas vazias e nomes inválidos; lê a última linha
# mesmo sem newline final. Arquivo inexistente é ignorado silenciosamente.
# Locais prefixados com __led_ para não colidir com chaves do arquivo.

load_env_defaults() {
  local __led_file="$1"
  local __led_line __led_key __led_value

  [ -f "$__led_file" ] || return 0

  while IFS= read -r __led_line || [ -n "$__led_line" ]; do
    __led_line="${__led_line%$'\r'}"
    # Remove espaços à esquerda
    __led_line="${__led_line#"${__led_line%%[![:space:]]*}"}"
    case "$__led_line" in
      '' | '#'*) continue ;;
    esac
    [[ "$__led_line" == *=* ]] || continue

    __led_key="${__led_line%%=*}"
    __led_value="${__led_line#*=}"
    __led_key="${__led_key#export }"
    __led_key="${__led_key%"${__led_key##*[![:space:]]}"}"

    [[ "$__led_key" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || continue

    # Remove aspas envolventes (simples ou duplas), como o `source` faria
    if [[ ${#__led_value} -ge 2 ]]; then
      if [[ "$__led_value" == \"*\" || "$__led_value" == \'*\' ]]; then
        __led_value="${__led_value:1:${#__led_value}-2}"
      fi
    fi

    if [ -z "${!__led_key+x}" ]; then
      printf -v "$__led_key" '%s' "$__led_value"
    fi
  done < "$__led_file"

  return 0
}
