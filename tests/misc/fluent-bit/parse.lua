local config = {
  hn = {
    columns = {'id', 'story_id', 'story_text', 'story_author', 'comment_id', 'comment_text', 'comment_author', 'comment_ranking', 'author_comment_count', 'story_comment_count'},
    skip_header = true,
    id_column = 'id',
    int_columns = {id=true, story_id=true, comment_id=true, comment_ranking=true, author_comment_count=true, story_comment_count=true},
    float_columns = {},
  },
  logs10m = {
    columns = {'id', 'remote_addr', 'remote_user', 'runtime', 'time_local', 'request_type', 'request_path', 'request_protocol', 'status', 'size', 'referer', 'usearagent'},
    skip_header = false,
    id_column = 'id',
    keep_id = true,
    int_columns = {id=true, runtime=true, time_local=true, status=true, size=true},
    float_columns = {},
  },
  logs10m_manticore = {
    columns = {'id', 'remote_addr', 'remote_user', 'runtime', 'time_local', 'request_type', 'request_path', 'request_protocol', 'status', 'size', 'referer', 'usearagent'},
    skip_header = false,
    id_column = 'id',
    int_columns = {id=true, runtime=true, time_local=true, status=true, size=true},
    float_columns = {},
  },
  taxi = {
    columns = {
      'trip_id', 'vendor_id', 'pickup_datetime', 'dropoff_datetime', 'store_and_fwd_flag',
      'rate_code_id', 'pickup_longitude', 'pickup_latitude', 'dropoff_longitude', 'dropoff_latitude',
      'passenger_count', 'trip_distance', 'fare_amount', 'extra', 'mta_tax', 'tip_amount',
      'tolls_amount', 'ehail_fee', 'improvement_surcharge', 'total_amount', 'payment_type',
      'trip_type', 'pickup', 'dropoff', 'cab_type', 'rain', 'snow_depth', 'snowfall',
      'max_temp', 'min_temp', 'wind', 'pickup_nyct2010_gid', 'pickup_ctlabel',
      'pickup_borocode', 'pickup_boroname', 'pickup_ct2010', 'pickup_boroct2010',
      'pickup_cdeligibil', 'pickup_ntacode', 'pickup_ntaname', 'pickup_puma',
      'dropoff_nyct2010_gid', 'dropoff_ctlabel', 'dropoff_borocode', 'dropoff_boroname',
      'dropoff_ct2010', 'dropoff_boroct2010', 'dropoff_cdeligibil', 'dropoff_ntacode',
      'dropoff_ntaname', 'dropoff_puma'
    },
    skip_header = false,
    id_column = 'trip_id',
    keep_id = true,
    int_columns = {
      trip_id=true, pickup_datetime=true, dropoff_datetime=true, rate_code_id=true,
      passenger_count=true, trip_type=true, max_temp=true, min_temp=true,
      pickup_nyct2010_gid=true, pickup_borocode=true, dropoff_nyct2010_gid=true,
      dropoff_borocode=true,
    },
    float_columns = {
      pickup_longitude=true, pickup_latitude=true, dropoff_longitude=true, dropoff_latitude=true,
      trip_distance=true, fare_amount=true, extra=true, mta_tax=true, tip_amount=true,
      tolls_amount=true, ehail_fee=true, improvement_surcharge=true, total_amount=true,
      rain=true, snow_depth=true, snowfall=true, wind=true,
    },
  },
  taxi_manticore = {
    columns = {
      'trip_id', 'vendor_id', 'pickup_datetime', 'dropoff_datetime', 'store_and_fwd_flag',
      'rate_code_id', 'pickup_longitude', 'pickup_latitude', 'dropoff_longitude', 'dropoff_latitude',
      'passenger_count', 'trip_distance', 'fare_amount', 'extra', 'mta_tax', 'tip_amount',
      'tolls_amount', 'ehail_fee', 'improvement_surcharge', 'total_amount', 'payment_type',
      'trip_type', 'pickup', 'dropoff', 'cab_type', 'rain', 'snow_depth', 'snowfall',
      'max_temp', 'min_temp', 'wind', 'pickup_nyct2010_gid', 'pickup_ctlabel',
      'pickup_borocode', 'pickup_boroname', 'pickup_ct2010', 'pickup_boroct2010',
      'pickup_cdeligibil', 'pickup_ntacode', 'pickup_ntaname', 'pickup_puma',
      'dropoff_nyct2010_gid', 'dropoff_ctlabel', 'dropoff_borocode', 'dropoff_boroname',
      'dropoff_ct2010', 'dropoff_boroct2010', 'dropoff_cdeligibil', 'dropoff_ntacode',
      'dropoff_ntaname', 'dropoff_puma'
    },
    skip_header = false,
    id_column = 'trip_id',
    int_columns = {
      trip_id=true, pickup_datetime=true, dropoff_datetime=true, rate_code_id=true,
      passenger_count=true, trip_type=true, max_temp=true, min_temp=true,
      pickup_nyct2010_gid=true, pickup_borocode=true, dropoff_nyct2010_gid=true,
      dropoff_borocode=true,
    },
    float_columns = {
      pickup_longitude=true, pickup_latitude=true, dropoff_longitude=true, dropoff_latitude=true,
      trip_distance=true, fare_amount=true, extra=true, mta_tax=true, tip_amount=true,
      tolls_amount=true, ehail_fee=true, improvement_surcharge=true, total_amount=true,
      rain=true, snow_depth=true, snowfall=true, wind=true,
    },
  },
}

local function parse_csv_line(line)
  local values = {}
  local field = {}
  local in_quote = false
  local i = 1
  while i <= #line do
    local ch = line:sub(i, i)
    if ch == '"' then
      local next_ch = line:sub(i + 1, i + 1)
      if in_quote and next_ch == '"' then
        field[#field + 1] = '"'
        i = i + 1
      else
        in_quote = not in_quote
      end
    elseif ch == ',' and not in_quote then
      values[#values + 1] = table.concat(field)
      field = {}
    else
      field[#field + 1] = ch
    end
    i = i + 1
  end
  values[#values + 1] = table.concat(field)
  return values
end

local function normalize_row(row, cfg)
  local out = {}
  if not cfg.keep_id and cfg.id_column and row[cfg.id_column] ~= nil and row[cfg.id_column] ~= '' then
    out['id'] = tostring(math.floor(tonumber(row[cfg.id_column]) or 0))
  end
  for key, value in pairs(row) do
    if (cfg.keep_id or key ~= cfg.id_column) and key ~= 'log' and key ~= 'time' and key ~= '@timestamp' then
      if value == '' or value == nil then
        out[key] = nil
      elseif cfg.keep_id and key == cfg.id_column then
        out[key] = tostring(math.floor(tonumber(value) or 0))
      elseif cfg.int_columns[key] then
        out[key] = math.floor(tonumber(value) or 0)
      elseif cfg.float_columns[key] then
        out[key] = tonumber(value) or 0
      else
        out[key] = tostring(value)
      end
    end
  end
  return out
end

local function parse_dataset(dataset, timestamp, record)
  local cfg = config[dataset]
  local line = record['log']
  if line == nil or line == '' then
    return -1, timestamp, record
  end
  if cfg.skip_header and (line:sub(1, 3) == 'id,' or line:sub(1, 5) == '"id",') then
    return -1, timestamp, record
  end
  local values = parse_csv_line(line)
  if #values ~= #cfg.columns then
    return -1, timestamp, record
  end
  local parsed = {}
  for i, column in ipairs(cfg.columns) do
    parsed[column] = values[i]
  end
  return 1, timestamp, normalize_row(parsed, cfg)
end

function parse_hn(tag, timestamp, record)
  return parse_dataset('hn', timestamp, record)
end

function parse_logs10m(tag, timestamp, record)
  return parse_dataset('logs10m', timestamp, record)
end

function parse_taxi(tag, timestamp, record)
  return parse_dataset('taxi', timestamp, record)
end

function parse_taxi_manticore(tag, timestamp, record)
  return parse_dataset('taxi_manticore', timestamp, record)
end

function normalize_hn_json(tag, timestamp, record)
  return 1, timestamp, normalize_row(record, config.hn)
end

function normalize_logs10m_json(tag, timestamp, record)
  return 1, timestamp, normalize_row(record, config.logs10m)
end

function normalize_logs10m_manticore_json(tag, timestamp, record)
  return 1, timestamp, normalize_row(record, config.logs10m_manticore)
end
