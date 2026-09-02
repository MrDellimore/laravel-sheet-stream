<table>
    <thead>
        <tr>
            <th>Name</th>
            <th>Email</th>
        </tr>
    </thead>
    <tbody>
        @foreach($rows as $row)
        <tr>
            <td>{{ $row['name'] }}</td>
            <td>{{ $row['email'] }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
